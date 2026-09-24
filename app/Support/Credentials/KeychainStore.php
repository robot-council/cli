<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Symfony\Component\Process\Process;

/**
 * The macOS Keychain, through `security`.
 *
 * **The token is written on stdin, never as an argument.** `security`'s own usage text says so:
 * "Use of the -p or -w options is insecure." A value passed as `-w <token>` sits in the process's
 * argv, which any local process can read with `ps` for as long as the call runs.
 *
 * **Through `security -i`, which reads its commands from stdin (cli#207).** The whole
 * `add-generic-password` line travels down the pipe, with the token as `-X <hex>`, so argv holds
 * nothing but `security -i` and the token needs no quoting whatever it contains.
 *
 * The earlier way, `-w` last and empty so that `security` prompted for the value, read that prompt
 * from the **controlling terminal** whenever there was one: run from a terminal, `enroll` printed
 * `password data for new item:`, ignored what it had piped, and timed out. It worked only without
 * a terminal, which is how every enrollment on record had run. Measured 2026-09-24 on macOS 26.6.2
 * with the pipe inside a pseudo-terminal: the prompt form hung until killed; this form stored all
 * twelve of the round-trip tokens byte for byte, with a terminal and without one.
 *
 * **The read-back is still the decision.** `security` exits 0 on outcomes that stored nothing
 * usable (measured for #46 on the prompt form), so `put()` reads the value back and compares,
 * which is the only check that discriminates. An item left with an empty password answers null
 * from `get()`, and the next write replaces it, because `-U` updates.
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
     * Bounded because a `security` that stops reading stdin -- as the prompt form did under a
     * terminal (cli#207) -- would otherwise hang a developer's terminal with no indication why.
     */
    // A tuning value, not a behaviour. `14` and `16` are indistinguishable from any test that does
    // not spend the difference waiting, and a test that did would assert the bound rather than
    // anything this class decides. Recorded on cli#46 rather than chased.
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    public const int TIMEOUT_SECONDS = 15;

    /**
     * The exit code `security` uses for an item that is not in the keychain.
     *
     * **Measured, because the assumption it replaces was wrong.** `robot-council/cli#39` reasoned
     * that `security` "exits non-zero both for a missing item and for a failure, so collapsing them
     * there loses nothing", and left this store returning null for every failure. On macOS 26.6.2
     * on 2026-09-22, with a successful lookup as the control:
     *
     * | case | exit |
     * | --- | --- |
     * | the item is found | `0` |
     * | the item is absent | **`44`**, `The specified item could not be found in the keychain` |
     * | an unknown subcommand | `1` |
     *
     * **The distinction this buys is narrower than it looks, and the bound is worth stating.**
     * Five ways of making the lookup fail for a reason other than absence were tried, and every
     * one answered `44` with that same message:
     *
     * | attempted fault | exit |
     * | --- | --- |
     * | a keychain file that does not exist | `44` |
     * | a malformed keychain file | `44` |
     * | a directory passed as the keychain | `44` |
     * | a **locked** keychain holding the item | `44` |
     * | `HOME` pointing at a path that does not exist | `44` |
     * | an unknown flag | `2` |
     * | an unknown subcommand | `1` |
     *
     * So `find-generic-password` answers `44` for anything it can read as "no such item", and
     * something else only for invocations it cannot parse -- which this store, whose arguments are
     * fixed, never makes. **The `!== 0` branch below is therefore defensive rather than
     * load-bearing**: it exists so a code nobody has seen is reported instead of silently read as
     * "not enrolled", and no test can reach it without replacing the binary.
     *
     * The case that is genuinely not improved is a **locked keychain**, which is reported as not
     * enrolled. That is a property of the tool rather than of this code.
     *
     * `robot-council/cli#85` asks the same question of `secret-tool`.
     */
    // Reported UNCOVERED rather than survived, which is a coverage artifact and not a gap: a
    // `const` declaration executes on no line, so the mutation run never reaches the tests that
    // depend on it. Measured instead by planting `43`, which turns FOUR tests red -- `it pins
    // NOT_FOUND to what `security` actually exits with` on the number itself, and three others
    // through `CredentialStoreFailed`, because every absent lookup then raises.
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    public const int NOT_FOUND = 44;

    /**
     * What `security -g` prefixes the password line with on stderr.
     */
    private const string PASSWORD_PREFIX = 'password: ';

    public function available(): bool
    {
        // `BooleanAndToBooleanOr` survives here and no test on one machine can kill it: on macOS
        // both operands are true and on anything else both are false, so `&&` and `||` agree
        // everywhere either can be observed. Separating them means injecting the platform, which
        // this class does not allow and which would test the injection. Recorded on cli#46.
        // @pest-mutate-ignore: BooleanAndToBooleanOr
        return PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/security');
    }

    public function describe(): string
    {
        return sprintf('the macOS Keychain, under the service `%s`', self::SERVICE);
    }

    public function put(string $service, Credential $credential): void
    {
        $token = $credential->reveal();

        // **Refused before anything is written.** A bearer token has no newline in it, and #41
        // asked for a defined outcome for these shapes rather than an accident. The prompt form
        // refused them by construction; hex could carry them, so the refusal is stated here instead.
        if ($token === '' || str_contains($token, "\n") || str_contains($token, "\r")) {
            throw new CredentialStoreFailed('The credential could not be stored in the macOS Keychain.');
        }

        // **The key is this package's own, built from a fleet URL and a harness name**, and it
        // travels inside a double-quoted argument that `security -i` parses. A character that could
        // end or escape the quoting is refused rather than escaped, since nothing legitimate has one.
        if (preg_match('/["\\\\\x00-\x1f]/', $service) === 1) {
            throw new CredentialStoreFailed('The credential could not be stored in the macOS Keychain.');
        }

        // `-U` updates rather than refusing when an item already exists, so re-enrolling against
        // the same service replaces the credential instead of erroring
        $write = new Process(['/usr/bin/security', '-i'], timeout: self::TIMEOUT_SECONDS);

        $write->setInput(\sprintf(
            "add-generic-password -a \"%s\" -s \"%s\" -U -X %s\n",
            $service,
            self::SERVICE,
            bin2hex($token)
        ));
        $write->run();

        // Not `$write->isSuccessful()`: it exits 0 on a mismatch. The read-back is the decision.
        //
        // Measured for cli#46: one line, or two that disagree, prints `passwords don't match`,
        // prompts again, exits **0**, and leaves an item with an EMPTY password. `get()` answers
        // null for that, so the raise below is reached -- and a failed `put()` leaves that empty
        // item behind, which the next `-U` write replaces.
        $stored = $this->get($service);

        // Two survivors live on this line and neither can be killed from a test.
        //
        // `BooleanOrToBooleanAnd` and `InstanceOfToTrue` both need the left half to be reachable
        // independently: a read-back that finds NOTHING while the write reported success. That is
        // the `security` behaviour this class exists to work around, and it cannot be produced on
        // demand against a real Keychain -- every way of making the write fail also makes the item
        // exist with an empty password, which `get()` turns into null via the same branch.
        // Injecting a fake `security` would kill them and would test the fake.
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, InstanceOfToTrue
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

        $exitCode = $read->getExitCode();

        // A machine that is simply not enrolled. The only answer here that is not a fault.
        if ($exitCode === self::NOT_FOUND) {
            return null;
        }

        // **Anything else non-zero is a broken mechanism, and reporting it is the whole point of
        // `NOT_FOUND` existing.** `CredentialStore::get()` requires an implementation that can tell
        // the two apart to raise rather than return null, because "not enrolled" sends an operator
        // to enroll again -- which stores a new credential through the same broken mechanism and
        // never states the fault. `WindowsCredentialStore` has done this since cli#39; cli#54
        // measured that `security` carries the same distinction and this store was discarding it.
        //
        // `getExitCode()` is null when the process never started, which is a fault rather than an
        // absence, so it lands here too.
        if ($exitCode !== 0) {
            // Nothing inside this branch can be reached from a test, for the reason the constant's
            // docblock measures: every way of making the lookup fail answers `44`, and the codes
            // that are not `44` come from invocations this store's fixed argument list cannot make.
            // `UnwrapRtrim` and `CoalesceRemoveLeft` below are both message formatting on that
            // path, so no input separates them from the original.
            // @pest-mutate-ignore: UnwrapRtrim, CoalesceRemoveLeft
            throw new CredentialStoreFailed(rtrim(sprintf(
                'The macOS Keychain could not be read: `security` exited %s. %s',
                $exitCode ?? 'without starting',
                $read->getErrorOutput()
            )));
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
     * **A credential that is empty, a single newline, or carries an embedded newline cannot be
     * stored at all**, and so never reaches here. Measured: `put()` raises `CredentialStoreFailed`
     * for each, because the value travels down the retype prompt as `$token\n$token\n` and a
     * newline inside it breaks that protocol, while an empty value produces `password: ` with no
     * quotes, which this reads as no credential. `get()` answers null for all three.
     *
     * **`WindowsCredentialStore` also calls `trim()`, and is right to.** It trims base64, where
     * surrounding whitespace is not significant and `base64_decode()` recovers the exact bytes.
     * The defect fixed here was trimming a RAW secret. A sweep that "fixes" the other store would
     * be removing a guard that does no harm.
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
