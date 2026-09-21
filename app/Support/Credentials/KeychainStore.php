<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Symfony\Component\Process\Process;

/**
 * The macOS Keychain, through `security`.
 *
 * **The token is written on stdin, never as an argument.** `security`'s own usage text says so:
 * "Use of the -p or -w options is insecure." A value passed as `-w <token>` sits in the process's
 * argv, which any local process can read with `ps` for as long as the call runs. Passing `-w` last
 * and empty makes it prompt instead, and the prompt reads stdin.
 *
 * Measured on macOS 26.6.2 on 2026-09-18, and both details cost a round of debugging:
 *
 * - The prompt asks to **retype**, so the value goes in twice. One line answers
 *   `passwords don't match`.
 * - It **exits 0 even when the passwords did not match**, so the exit code proves nothing here.
 *   `put()` therefore reads the value back and compares, which is the only check that discriminates.
 */
final class KeychainStore implements CredentialStore
{
    /**
     * The Keychain service name every item is filed under.
     */
    public const string SERVICE = 'robot-council';

    /**
     * How long any one `security` call may take.
     *
     * Bounded because the write path deliberately uses an interactive prompt, and a prompt that
     * stops reading stdin would otherwise hang a developer's terminal with no indication why.
     */
    public const int TIMEOUT_SECONDS = 15;

    /**
     * What `security -g` prefixes the password line with on stderr.
     */
    private const string PASSWORD_PREFIX = 'password: ';

    public function available(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/security');
    }

    public function describe(): string
    {
        return sprintf('the macOS Keychain, under the service `%s`', self::SERVICE);
    }

    public function put(string $service, Credential $credential): void
    {
        $token = $credential->reveal();

        // `-U` updates rather than refusing when an item already exists, so re-enrolling against
        // the same service replaces the credential instead of erroring
        $write = new Process(
            ['/usr/bin/security', 'add-generic-password', '-a', $service, '-s', self::SERVICE, '-U', '-w'],
            timeout: self::TIMEOUT_SECONDS,
        );

        // Twice, for the retype prompt
        $write->setInput($token."\n".$token."\n");
        $write->run();

        // Not `$write->isSuccessful()`: it exits 0 on a mismatch. The read-back is the decision.
        $stored = $this->get($service);

        if (! $stored instanceof Credential || ! $stored->equals($credential)) {
            throw new CredentialStoreFailed('The credential could not be stored in the macOS Keychain.');
        }
    }

    public function get(string $service): ?Credential
    {
        // `-g` rather than `-w`, and that is the whole of #41. `-w` prints the password raw, which
        // loses two things: it appends a newline that cannot be told from the credential's own
        // trailing whitespace, and for any byte above 0x7F -- or a backslash -- it prints a HEX
        // DUMP of the whole password with no prefix, indistinguishable from a credential that is
        // legitimately a run of hex characters. Measured on macOS 26.6.2: `caf<U+00E9>` came back
        // as the ten ASCII characters `636166c3a9`, and `'  padded  '` came back as `'padded'`.
        //
        // `-g` writes the password to STDERR in a delimited form that distinguishes every case.
        $read = new Process(
            ['/usr/bin/security', 'find-generic-password', '-a', $service, '-s', self::SERVICE, '-g'],
            timeout: self::TIMEOUT_SECONDS,
        );

        $read->run();

        // An unsuccessful read is a missing item, and the parser below would reach the same answer
        // from the empty stderr it leaves -- so this is an early exit rather than a decision, and
        // no input can kill its removal.
        // @pest-mutate-ignore: RemoveEarlyReturn
        if (! $read->isSuccessful()) {
            return null;
        }

        $value = $this->password($read->getErrorOutput());

        // An item with an empty password is not a credential. It cannot be created through `put()`,
        // which reads back and compares, so reaching this means somebody else wrote it.
        return $value === null ? null : new Credential($value);
    }

    /**
     * The password out of what `security -g` wrote to stderr.
     *
     * **Three forms, and which one appears is decided by the bytes rather than by a flag.** Every
     * row below was measured on macOS 26.6.2 on 2026-09-21 by storing the value and reading it back:
     *
     * | stored | the `password:` line |
     * | --- | --- |
     * | `rcouncil_1\|abcdef` | `password: "rcouncil_1\|abcdef"` |
     * | `  padded  ` | `password: "  padded  "` -- the quotes delimit it, so no trimming |
     * | `caf<U+00E9>` | `password: 0x636166C3A9  "caf\303\251"` |
     * | `ab\cd` | `password: 0x61625C6364  "ab\134cd"` -- a backslash forces the hex form too |
     * | `ab"cd` | `password: "ab"cd"` -- a quote is NOT escaped |
     * | `0xDEADBEEF` | `password: "0xDEADBEEF"` -- quoted, so it cannot be read as the hex form |
     * | (empty) | `password: ` |
     *
     * **The hex form is preferred wherever it appears**, because it is the only unambiguous one,
     * and because everything that would need escaping inside the quotes -- a backslash, any
     * non-ASCII byte -- forces it. That leaves the quoted branch holding printable ASCII with no
     * backslash, where taking everything between the FIRST and LAST quote is exact even when the
     * value itself contains quotes.
     *
     * The discriminator is one character: the quoted form always begins with `"`, so a credential
     * that merely starts with `0x` cannot be mistaken for a hex dump.
     *
     * @param  string  $stderr  What `security -g` wrote to stderr.
     * @return string|null The password, or null when no password line was written.
     */
    private function password(string $stderr): ?string
    {
        foreach (explode("\n", $stderr) as $candidate) {
            if (str_starts_with($candidate, self::PASSWORD_PREFIX)) {
                return $this->decode(substr($candidate, \strlen(self::PASSWORD_PREFIX)));
            }
        }

        return null;
    }

    /**
     * One `password:` line's value, in whichever of the three forms it arrived in.
     *
     * @param  string  $line  Everything after `password: `.
     * @return string|null The password, or null when the line carries none.
     */
    private function decode(string $line): ?string
    {
        // Pairs of hex digits, because a byte dump is always an even number of them. Written that
        // way rather than as `+` so that `hex2bin` below cannot be reached with something it would
        // refuse, which is one fewer branch that no input can exercise.
        if (preg_match('/^0x((?:[0-9A-Fa-f]{2})+)/', $line, $matches) === 1) {
            $decoded = hex2bin($matches[1]);

            // Unreachable by construction -- the pattern admits only well-formed, even-length hex.
            // The guard is here because `hex2bin` is declared `string|false` and PHPStan enforces
            // the declaration, so no input can kill this mutant without failing the other gate.
            // @pest-mutate-ignore: FalseToTrue
            return $decoded === false ? null : $decoded;
        }

        // Quoted, and the quotes are what delimit the value -- which is why nothing is trimmed.
        // Dropping the first and last character rather than searching for the closing quote,
        // because `security` does not escape a quote inside the value: `ab"cd` arrives as
        // `"ab"cd"`, and a search for the first closing quote would truncate it at `ab`.
        //
        // Anything not quoted is the empty password, which is not a credential. Measured: storing
        // an empty value produces `password: ` with no quotes at all, so `""` never arrives.
        //
        // Every line `security` writes either opens and closes with a quote or carries none, so
        // the two tests agree on every input that can reach here and no input can kill this one.
        // @pest-mutate-ignore: StrStartsWithToStrEndsWith
        return str_starts_with($line, '"') ? substr($line, 1, -1) : null;
    }

    public function forget(string $service): void
    {
        $delete = new Process(
            ['/usr/bin/security', 'delete-generic-password', '-a', $service, '-s', self::SERVICE],
            timeout: self::TIMEOUT_SECONDS,
        );

        // A missing item exits non-zero, which is the state being asked for rather than a failure
        $delete->run();
    }
}
