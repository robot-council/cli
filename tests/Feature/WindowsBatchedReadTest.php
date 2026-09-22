<?php

declare(strict_types=1);

/**
 * `WindowsCredentialStore::getMany()`: one `powershell.exe` invocation for the existence question.
 *
 * **Gated on a reachable Credential Manager, and it writes to the real one**, exactly as the rest
 * of this store's round-trip tests do. Every key is random and `afterEach` removes what was written.
 *
 * What is pinned here is the shape the batch must keep, not its speed alone: the map comes back
 * under the caller's own keys, an omission cannot shift later answers onto the wrong key, and a
 * batch that finds nothing is told apart from one that never ran. The figures behind the design are
 * on `robot-council/cli#90`.
 *
 * @command  vendor/bin/pest --compact tests/Feature/WindowsBatchedReadTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStoreFailed;
use App\Support\Credentials\ReadsManyCredentials;
use App\Support\Credentials\WindowsCredentialStore;
use Symfony\Component\Process\Process;

const BATCH_TOKEN = 'batched-token-value';

/**
 * Skip unless this machine can actually reach Credential Manager.
 *
 * Memoized, and named distinctly from the sibling in `WindowsCredentialStoreTest` because Pest
 * loads every test file into one process and a repeated function name is a fatal redeclaration.
 */
function batchedReadNeedsCredentialManager(): bool
{
    static $available = null;

    $available ??= new WindowsCredentialStore()->available();

    return ! $available;
}

beforeEach(function (): void {
    $this->store = new WindowsCredentialStore;
    $this->fleet = 'https://batch-'.bin2hex(random_bytes(6)).'.example.test';

    $this->key = fn (string $harness): string => $this->fleet.'|'.$harness;

    // **Every harness any test here touches, seeded up front rather than appended as tests write.**
    // `afterEach` removes exactly these, so a test that fails partway cannot leave a credential in
    // the developer's real Credential Manager. Forgetting a key that was never written is a no-op
    // -- `it forgets a service it never held without complaining` says so next door -- which is
    // what makes seeding the whole set cheaper than tracking what each test wrote.
    $this->written = array_map($this->key, ['claude', 'cursor', 'codex', 'copilot', 'gemini', 'devin', 'amp', 'pi']);

    $this->enroll = function (string $harness): string {
        $key = ($this->key)($harness);
        $this->store->put($key, new Credential(BATCH_TOKEN.'-'.$harness));

        return $key;
    };
});

afterEach(function (): void {
    // Credential Manager is the developer's real one, so anything this suite wrote comes back out
    // whether the test passed or not. No guard on the properties being set: PHPUnit skips
    // `tearDown` when `setUp` throws, so reaching here means `beforeEach` finished.
    if (batchedReadNeedsCredentialManager()) {
        return;
    }

    foreach ($this->written as $key) {
        $this->store->forget($key);
    }
});

it('answers every key in one invocation, not one apiece', function (): void {
    // **Timed rather than counted, because the process is spawned inside the store.** The bound is
    // deliberately loose: measured on 2026-09-22 the batch answered fifteen absent keys in 390ms
    // against 5,724ms serial, a factor of 14.7. Requiring only a factor of three leaves room for a
    // slower machine while still failing outright if the batch has quietly become a loop.
    $keys = array_map($this->key, ['claude', 'cursor', 'codex', 'copilot', 'gemini', 'devin', 'amp', 'pi']);

    $startedSerial = microtime(true);

    foreach ($keys as $key) {
        $this->store->get($key);
    }

    $serial = microtime(true) - $startedSerial;

    $startedBatch = microtime(true);
    $found = $this->store->getMany($keys);
    $batched = microtime(true) - $startedBatch;

    expect($found)->toBeEmpty()
        ->and($batched)->toBeLessThan($serial / 3);
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('comes back under the keys it was asked with, not the targets it looked up', function (): void {
    // **The failure this prevents is silent and total.** `target()` maps a key through
    // `WindowsCredentialTarget::for()` to `robot-council:<key>#<digest>`, so a batch answering in
    // target-name space returns a map whose every key misses `storedAmong()`'s `isset()`, and a
    // fully enrolled machine then reports nothing stored.
    $claude = ($this->enroll)('claude');

    $found = $this->store->getMany([$claude]);

    expect(array_keys($found))->toBe([$claude])
        ->and($found[$claude]->reveal())->toBe(BATCH_TOKEN.'-claude');
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('puts each credential under its own key when an earlier one is absent', function (): void {
    // **The positional trap, which the contract creates.** `ReadsManyCredentials` requires a key
    // that could not be read to be omitted, so an answer carrying position rather than identity
    // slips by one at the first gap and hands back every later credential under the wrong key --
    // which for this store is one harness receiving another's credential.
    $claude = ($this->enroll)('claude');
    $absent = ($this->key)('cursor');
    $codex = ($this->enroll)('codex');

    $found = $this->store->getMany([$claude, $absent, $codex]);

    expect($found[$claude]->reveal())->toBe(BATCH_TOKEN.'-claude')
        ->and($found[$codex]->reveal())->toBe(BATCH_TOKEN.'-codex')
        ->and($found)->not->toHaveKey($absent);
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('agrees with reading the same keys one at a time', function (): void {
    // The criterion that keeps this override honest: whatever the batch does differently, it must
    // answer what the default would have answered.
    $claude = ($this->enroll)('claude');
    $asked = [($this->key)('cursor'), $claude, ($this->key)('codex')];

    $serial = [];

    foreach ($asked as $key) {
        $credential = $this->store->get($key);

        if ($credential instanceof Credential) {
            $serial[$key] = $credential->reveal();
        }
    }

    $batched = array_map(
        static fn (Credential $credential): string => $credential->reveal(),
        $this->store->getMany($asked),
    );

    expect($batched)->toBe($serial);
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('tells a batch that found nothing from one that never ran', function (): void {
    // `robot-council/cli#31`'s first prototype reported `442ms, found 0` having silently inserted
    // nothing and fallen through to the script's `default { exit 4 }`. An empty answer cannot
    // distinguish the two, so the same call, on the same store, has to find a key that is there.
    $claude = ($this->enroll)('claude');

    expect($this->store->getMany([($this->key)('cursor'), ($this->key)('codex')]))->toBeEmpty()
        ->and($this->store->getMany([$claude]))->toHaveCount(1);
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('does not answer for a key that differs only in case', function (): void {
    // Credential Manager matches target names case-insensitively, which is why `for()` carries a
    // digest. The batch must inherit that separation rather than reintroduce a collision by
    // echoing back whatever casing the backend reported.
    $claude = ($this->enroll)('claude');

    expect($this->store->getMany([strtoupper($claude)]))->toBeEmpty();
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('asks nothing at all when given no keys', function (): void {
    // A batch of nothing still costs an `Add-Type` compile on this store, which is the one thing
    // this ticket exists to stop paying for.
    $started = microtime(true);

    expect($this->store->getMany([]))->toBeEmpty()
        ->and(microtime(true) - $started)->toBeLessThan(0.1);
});

it('raises rather than reporting nothing stored when the mechanism is broken', function (): void {
    // `robot-council/cli#39`, carried into the batch: a store that can tell a fault from an absence
    // must not report the fault as "not enrolled". **A batch makes that sharper**, because
    // reporting a broken mechanism as "none of these fifteen is enrolled" is a far larger false
    // statement than making it about one key.
    //
    // `Z:\no-such-dir` rather than a missing directory on a real drive, which is the lever the
    // single-key test next door uses. Measured while writing this: a nonexistent path on `C:` does
    // NOT break `Add-Type` on this machine -- the helper still answered `NOT_FOUND` -- so a test
    // written against one would have asserted a throw that never came.
    $child = static function (?string $temporaryDirectory): string {
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            foreach (['Credential', 'CredentialStore', 'CredentialStoreFailed', 'ReadsManyCredentials', 'WindowsCredentialStore'] as $class) {
                require $argv[1].'/app/Support/Credentials/'.$class.'.php';
            }
            try {
                $found = (new App\Support\Credentials\WindowsCredentialStore)->getMany([$argv[2], $argv[3]]);
                fwrite(STDOUT, 'RETURNED '.\count($found));
            } catch (RuntimeException $e) {
                fwrite(STDOUT, 'THREW '.$e::class.': '.$e->getMessage());
            }
            PHP;

        $process = new Process(
            [PHP_BINARY, '-r', $code, \dirname(__DIR__, 2),
                'https://broken-'.bin2hex(random_bytes(4)).'.example.test|claude',
                'https://broken-'.bin2hex(random_bytes(4)).'.example.test|cursor'],
            timeout: WindowsCredentialStore::TIMEOUT_SECONDS * 2,
        );

        $process->setEnv($temporaryDirectory === null
            ? []
            : ['TMP' => $temporaryDirectory, 'TEMP' => $temporaryDirectory]);

        $process->run();

        return trim($process->getOutput());
    };

    // **Control first.** A healthy child must come back with an answer for keys nothing is filed
    // under. Without it a THREW below could be the harness failing rather than the branch under
    // test, and the two are indistinguishable from the assertion alone.
    expect($child(null))->toBe('RETURNED 0');

    // The same call where the helper cannot run. Caught as `RuntimeException` deliberately: that is
    // what `ApiCommand` and `McpCommand` wrap credential resolution in, so this asserts the fault
    // reaches an operator rather than escaping to Collision, which renders a trace.
    expect($child('Z:\no-such-dir'))
        ->toStartWith('THREW '.CredentialStoreFailed::class.':')
        ->toContain('could not be asked which credentials it holds');
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('raises when every key it was asked about failed, rather than reporting none enrolled', function (): void {
    // **The conflation this closes is `robot-council/cli#39` at the worst scale.** A batch whose
    // every read failed, answering silently, tells an operator that none of fifteen harnesses is
    // enrolled -- and the remedy that invites is to enroll again through the same broken store.
    //
    // The lever is a target past the length Credential Manager accepts, which makes `CredRead`
    // fail with something other than `ERROR_NOT_FOUND`. Measured while writing this: a target of
    // 32,768 characters exits 5 where a normal absent target exits 0.
    $oversized = 'https://'.str_repeat('a', 33000).'.example.test|claude';

    expect(fn (): array => $this->store->getMany([$oversized]))
        ->toThrow(CredentialStoreFailed::class, 'refused every credential it was asked about');

    // **The control.** The same call with an ordinary absent key answers instead of raising, so the
    // throw above is about the mechanism rather than about `getMany()` refusing whatever it is fed.
    expect($this->store->getMany([($this->key)('cursor')]))->toBeEmpty();
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('omits the key it could not read and answers for the rest', function (): void {
    // The other half of the same contract. One failure is an omission -- `ReadsManyCredentials`
    // says so -- and only every failure is a broken mechanism. Without this test the guard above
    // could be tightened into raising on any failure and nothing would notice.
    $claude = ($this->enroll)('claude');
    $oversized = 'https://'.str_repeat('a', 33000).'.example.test|cursor';

    $found = $this->store->getMany([$oversized, $claude]);

    expect($found)->toHaveKey($claude)
        ->and($found[$claude]->reveal())->toBe(BATCH_TOKEN.'-claude')
        ->and($found)->not->toHaveKey($oversized);
})->skip(batchedReadNeedsCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('reads the helper answer it understands and drops the rest', function (string $label, string $output, array $expected): void {
    // **These run on every platform, and they are the only tests here that do.** Everything else in
    // this file needs a reachable Credential Manager, so on ubuntu and macOS -- most of the CI
    // matrix -- nothing else exercises a line of this parsing.
    //
    // They also exist because the two branches below could not be reached any other way. The script
    // is a constant, so no test can make it answer with a junk line or an index past the request;
    // hand mutation on 2026-09-22 confirmed both branches survived with the suite green while the
    // parsing lived inside the process call.
    $services = ['alpha', 'bravo', 'charlie'];

    expect(WindowsCredentialStore::servicesNamedIn($output, $services))->toBe($expected, $label);
})->with([
    ['one hit', "1\n", ['bravo']],
    ['several, in the order named', "2\n0\n", ['charlie', 'alpha']],
    ['nothing found', '', []],
    ['trailing blank lines', "0\n\n\n", ['alpha']],
    ['carriage returns, as a Windows child writes them', "0\r\n2\r\n", ['alpha', 'charlie']],
    // An index past the request would otherwise reach `get()` as a credential key.
    ['an index past the request', "3\n", []],
    ['a negative index', "-1\n", []],
    // A line that is not an index at all. Without the check `(int)` makes it 0, which invents a hit
    // on the first service -- the one failure mode here that could report a credential that is not
    // there rather than miss one that is.
    ['a line that is not a number', "abc\n", []],
    ['a number with something after it', "1x\n", []],
    ['junk beside a real hit', "abc\n1\n", ['bravo']],
    ['nothing but junk', "not-an-index\n", []],
]);

it('invents nothing when the helper answers with a service it was never asked about', function (): void {
    // The control that makes the cases above mean something: the same parser, given an answer it
    // does understand, returns the service. Without it every expectation of `[]` would pass against
    // a parser that returned `[]` for everything.
    expect(WindowsCredentialStore::servicesNamedIn("0\n", ['only']))->toBe(['only'])
        ->and(WindowsCredentialStore::servicesNamedIn("1\n", ['only']))->toBeEmpty();
});
