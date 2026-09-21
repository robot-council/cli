<?php

declare(strict_types=1);

/**
 * One credential per harness per fleet, which is what `robot-council/cli#21` decided.
 *
 * Exercised against the real `UserFileStore` rather than a double, because the thing under test is
 * the key and a double would agree with whatever key it was handed.
 *
 * **Constants here are named for this file.** `phpunit.xml.dist` sets `failOnWarning`, and a global
 * `const` redeclared across two test files makes PHP warn that the statement has no effect -- a run
 * that prints `Tests: N passed` and exits 1, with nothing in the summary saying why.
 *
 * @command  vendor/bin/pest --compact tests/Feature/CredentialKeyTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialKey;
use App\Support\Credentials\Credentials;
use App\Support\Credentials\UserFileStore;

const KEYED_FLEET = 'https://fleet.example.test';
const KEYED_CLAUDE_TOKEN = 'rcouncil_1|CLAUDE-TOKEN-0123456789abcdef';
const KEYED_CURSOR_TOKEN = 'rcouncil_2|CURSOR-TOKEN-0123456789abcdef';

/**
 * A `Credentials` backed by a real file store in a directory this test owns.
 */
function keyedCredentials(): Credentials
{
    return new Credentials([new UserFileStore]);
}

beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/rc-keyed-'.bin2hex(random_bytes(6));

    mkdir($this->home, 0o700, true);

    putenv('XDG_CONFIG_HOME='.$this->home);
});

afterEach(function (): void {
    putenv('XDG_CONFIG_HOME');
});

it('holds a credential for each harness rather than one for the fleet', function (): void {
    $credentials = keyedCredentials();

    $credentials->put(KEYED_FLEET, 'claude', new Credential(KEYED_CLAUDE_TOKEN));
    $credentials->put(KEYED_FLEET, 'cursor', new Credential(KEYED_CURSOR_TOKEN));

    // Each reads back the one it stored, which is the whole point: before this, the second enroll
    // replaced the first and one harness was left holding the other's credential
    expect($credentials->get(KEYED_FLEET, 'claude')?->reveal())->toBe(KEYED_CLAUDE_TOKEN)
        ->and($credentials->get(KEYED_FLEET, 'cursor')?->reveal())->toBe(KEYED_CURSOR_TOKEN);
});

it('replaces one harness on re-enrollment and leaves the others alone', function (): void {
    $credentials = keyedCredentials();

    $credentials->put(KEYED_FLEET, 'claude', new Credential(KEYED_CLAUDE_TOKEN));
    $credentials->put(KEYED_FLEET, 'cursor', new Credential(KEYED_CURSOR_TOKEN));

    $credentials->put(KEYED_FLEET, 'claude', new Credential('rcouncil_3|CLAUDE-AGAIN-0123456789'));

    // Asserted by reading both back rather than by counting: a count of two is equally true of a
    // store that replaced the wrong one
    expect($credentials->get(KEYED_FLEET, 'claude')?->reveal())->toBe('rcouncil_3|CLAUDE-AGAIN-0123456789')
        ->and($credentials->get(KEYED_FLEET, 'cursor')?->reveal())->toBe(KEYED_CURSOR_TOKEN);
});

it('forgets one harness without disturbing another', function (): void {
    $credentials = keyedCredentials();

    $credentials->put(KEYED_FLEET, 'claude', new Credential(KEYED_CLAUDE_TOKEN));
    $credentials->put(KEYED_FLEET, 'cursor', new Credential(KEYED_CURSOR_TOKEN));

    $credentials->forget(KEYED_FLEET, 'claude');

    expect($credentials->get(KEYED_FLEET, 'claude'))->toBeNull()
        ->and($credentials->get(KEYED_FLEET, 'cursor')?->reveal())->toBe(KEYED_CURSOR_TOKEN);
});

it('keeps two fleets apart, as it always did', function (): void {
    $credentials = keyedCredentials();

    $credentials->put(KEYED_FLEET, 'claude', new Credential(KEYED_CLAUDE_TOKEN));
    $credentials->put('https://other.example.test', 'claude', new Credential(KEYED_CURSOR_TOKEN));

    expect($credentials->get(KEYED_FLEET, 'claude')?->reveal())->toBe(KEYED_CLAUDE_TOKEN)
        ->and($credentials->get('https://other.example.test', 'claude')?->reveal())->toBe(KEYED_CURSOR_TOKEN);
});

it('does not hand a legacy credential to whichever harness asks first', function (): void {
    // The inference #21 refused. A machine enrolled before harness keying has a bare-fleet entry,
    // and it belongs to whichever harness enrolled it -- which this code cannot know.
    new UserFileStore()->put(CredentialKey::legacy(KEYED_FLEET), new Credential(KEYED_CLAUDE_TOKEN));

    $credentials = keyedCredentials();

    expect($credentials->get(KEYED_FLEET, 'claude'))->toBeNull()
        ->and($credentials->get(KEYED_FLEET, 'cursor'))->toBeNull()

        // It is readable, which is what stops any machine having to re-enroll
        ->and($credentials->legacy(KEYED_FLEET)?->reveal())->toBe(KEYED_CLAUDE_TOKEN);
});

it('claims a legacy credential for one named harness', function (): void {
    new UserFileStore()->put(CredentialKey::legacy(KEYED_FLEET), new Credential(KEYED_CLAUDE_TOKEN));

    $credentials = keyedCredentials();

    expect($credentials->adopt(KEYED_FLEET, 'claude')?->reveal())->toBe(KEYED_CLAUDE_TOKEN)

        // And afterwards it is where every other read expects it
        ->and($credentials->get(KEYED_FLEET, 'claude')?->reveal())->toBe(KEYED_CLAUDE_TOKEN)

        // Claimed for the harness that asked, and no other
        ->and($credentials->get(KEYED_FLEET, 'cursor'))->toBeNull();
});

it('claims nothing when there is nothing to claim', function (): void {
    expect(keyedCredentials()->adopt(KEYED_FLEET, 'claude'))->toBeNull();
});

it('cannot be confused by a separator in either half of the key', function (): void {
    // The key is unambiguous rather than merely unlikely to collide: a harness is `[a-z0-9-]` and
    // a bar is not legal unencoded in a URL. This is the guard that says so if either changes.
    expect(CredentialKey::for('https://fleet.example.test', 'claude'))
        ->toBe('https://fleet.example.test'.CredentialKey::SEPARATOR.'claude')
        ->and(preg_match('/^[a-z0-9-]+$/D', CredentialKey::SEPARATOR))->toBe(0)
        ->and(rawurlencode(CredentialKey::SEPARATOR))->toBe('%7C');
});

it('leaves every store ignorant of the key it is handed', function (): void {
    // The structural half of #23: the key is built in one place. A store that reasoned about the
    // key's shape would have to be changed for the Windows one (#7) to work, and this is what says
    // none does. The control is that the same search DOES find it where it belongs.
    $stores = glob(__DIR__.'/../../app/Support/Credentials/*Store.php') ?: [];

    expect($stores)->not->toBeEmpty();

    foreach ($stores as $store) {
        expect((string) file_get_contents($store))
            ->not->toContain('CredentialKey')
            ->not->toContain(CredentialKey::SEPARATOR."'");
    }

    expect((string) file_get_contents(__DIR__.'/../../app/Support/Credentials/Credentials.php'))
        ->toContain('CredentialKey');
});
