<?php

declare(strict_types=1);

/**
 * The fallback store, for machines with no OS credential store.
 *
 * It is the store most likely to be used on a server, and the one where getting it wrong is silent:
 * a world-readable file works exactly as well as a correct one until somebody reads it.
 *
 * @command  vendor/bin/pest --compact tests/Feature/UserFileStoreTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\Credentials\CredentialStore;
use App\Support\Credentials\KeychainStore;
use App\Support\Credentials\SecretToolStore;
use App\Support\Credentials\UserFileStore;
use App\Support\Credentials\WindowsCredentialStore;
use App\Support\Credentials\WindowsFfiCredentialStore;

const TOKEN = 'rcouncil_1|SuPeRsEcReTvAlUe0123456789abcdef';
const OTHER = 'https://other.example.test';

beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/rc-store-'.bin2hex(random_bytes(6));

    mkdir($this->home, 0700, true);

    putenv('XDG_CONFIG_HOME='.$this->home);

    $this->store = new UserFileStore;
});

afterEach(function (): void {
    putenv('XDG_CONFIG_HOME');

    if (is_dir($this->home)) {
        // Not `rm -rf`, which is not a command under Windows PHP and would leak the directory
        // rather than failing loudly
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->home);
    }
});

it('stores a credential and reads it back', function (): void {
    $this->store->put('https://fleet.example.test', new Credential(TOKEN));

    expect($this->store->get('https://fleet.example.test')?->reveal())->toBe(TOKEN);
});

it('writes the file so only its owner can read it', function (): void {
    $this->store->put('https://fleet.example.test', new Credential(TOKEN));

    $mode = fileperms($this->store->path()) & 0777;

    // Narrowed BEFORE the token goes in. Writing first and chmod-ing after leaves the credential
    // world-readable for the width of that gap, which is the kind of window nothing reports.
    expect(decoct($mode))->toBe('600');
})->skip(
    PHP_OS_FAMILY === 'Windows',
    'chmod only toggles the read-only bit on Windows, so this guarantee does not hold there.'
);

it('says plainly that the mode guarantee does not hold on Windows', function (): void {
    // The skip above would otherwise be the only record that this store gives Windows no mode
    // guarantee at all, and a skipped test is easy to read as "covered elsewhere". It is not.
    // `WindowsCredentialStore` means a Windows machine usually never reaches this store -- but a
    // machine where Credential Manager cannot be used lands here, with exactly this gap.
    $this->store->put('https://fleet.example.test', new Credential(TOKEN));

    expect($this->store->path())->toBeFile()
        ->and($this->store->get('https://fleet.example.test')?->reveal())->toBe(TOKEN);

    // What Windows does get: the file is under the user's profile rather than the drive root, which
    // is what the `USERPROFILE` fallback in `path()` exists for.
    expect($this->store->path())->toStartWith($this->home);
})->onlyOnWindows();

it('keeps two services apart rather than overwriting one with the other', function (): void {
    $this->store->put('https://fleet.example.test', new Credential(TOKEN));
    $this->store->put(OTHER, new Credential('a-different-credential'));

    // A machine enrolled against two deployments holds two credentials. Keyed on anything else,
    // the second enrollment would silently log the machine out of the first.
    expect($this->store->get('https://fleet.example.test')?->reveal())->toBe(TOKEN)
        ->and($this->store->get(OTHER)?->reveal())->toBe('a-different-credential');
});

it('writes outside any repository', function (): void {
    $this->store->put('https://fleet.example.test', new Credential(TOKEN));

    $path = $this->store->path();

    // Under the user's config directory, never the working directory. A credential written beside
    // a project gets committed, and that is the failure this whole design exists to avoid.
    expect($path)->toStartWith($this->home)
        ->and($path)->not->toStartWith(getcwd() ?: '/nonexistent');
});

it('forgets one service without disturbing another', function (): void {
    $this->store->put('https://fleet.example.test', new Credential(TOKEN));
    $this->store->put(OTHER, new Credential('a-different-credential'));

    $this->store->forget('https://fleet.example.test');

    expect($this->store->get('https://fleet.example.test'))->toBeNull()
        ->and($this->store->get(OTHER)?->reveal())->toBe('a-different-credential');
});

it('removes the file once nothing is left in it', function (): void {
    $this->store->put('https://fleet.example.test', new Credential(TOKEN));
    $this->store->forget('https://fleet.example.test');

    expect($this->store->path())->not->toBeFile();
});

it('reads a corrupt file as empty rather than throwing', function (): void {
    $this->store->put('https://fleet.example.test', new Credential(TOKEN));

    file_put_contents($this->store->path(), '{ not json at all');

    // A developer who finds this file mangled should be able to re-enroll, not hand-edit JSON
    expect($this->store->get('https://fleet.example.test'))->toBeNull();

    $this->store->put('https://fleet.example.test', new Credential(TOKEN));

    expect($this->store->get('https://fleet.example.test')?->reveal())->toBe(TOKEN);
});

it('reports itself available unconditionally, which is what makes it the fallback', function (): void {
    expect((new UserFileStore)->available())->toBeTrue();
});

it('is the last candidate, behind every store that can be absent', function (): void {
    // Pinned by class and by ORDER, on every platform. An earlier version of this test recomputed
    // `store()`'s own rule from the same `available()` calls the production code makes and
    // compared the two, which can only fail if the test's copy of the list diverges -- on ubuntu
    // it asserted `UserFileStore === UserFileStore` and would have stayed green with
    // `WindowsCredentialStore` removed from the candidates entirely.
    //
    // **The two Windows stores' relative order is load-bearing, not incidental.** Both reach the
    // same Credential Manager and write interchangeable entries; the FFI one calls `advapi32`
    // in-process and the PowerShell one starts a subprocess to do it. `store()` takes the first
    // available candidate, so listing the FFI store second would mean a machine that has FFI never
    // uses it, with nothing to report that but a suite still passing. `robot-council/cli#50`.
    expect(array_map(fn (CredentialStore $store): string => $store::class, Credentials::candidates()))
        ->toBe([
            KeychainStore::class,
            SecretToolStore::class,
            WindowsFfiCredentialStore::class,
            WindowsCredentialStore::class,
            UserFileStore::class,
        ]);
});

it('is passed over for the first store that reports itself available', function (): void {
    // Fakes rather than the real candidates, so this asserts `store()`'s rule -- first available
    // wins -- on every platform instead of asking the machine what it happens to have.
    $fake = fn (bool $available): CredentialStore => new readonly class($available) implements CredentialStore
    {
        public function __construct(private bool $available) {}

        public function available(): bool
        {
            return $this->available;
        }

        public function describe(): string
        {
            return 'a fake';
        }

        public function put(string $service, Credential $credential): void {}

        public function get(string $service): ?Credential
        {
            return null;
        }

        public function forget(string $service): void {}
    };

    $unavailable = $fake(false);
    $available = $fake(true);

    expect(new Credentials([$unavailable, $available, new UserFileStore])->store())->toBe($available)
        ->and(new Credentials([$unavailable, new UserFileStore])->store())->toBeInstanceOf(UserFileStore::class);
});
