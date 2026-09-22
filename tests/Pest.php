<?php

declare(strict_types=1);

use Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * The first non-empty line of a child process's stderr, for a failure message.
 *
 * **A test that reports only an exit code costs the next reader the whole diagnosis.**
 * `robot-council/cli#76` is two CI instances of exactly that: `Failed asserting that 255 is
 * identical to 0`, twice, with the reason sitting unread in the child's stderr. 255 is PHP's exit
 * code for an uncaught exception, so the child had already written `Uncaught <class>: <message>`
 * -- the one line that names the fault -- and the assertion threw it away.
 *
 * **Here rather than in one test file** because several tests drive children the same way and every
 * one of them had the same gap. The files each declare their own helpers under distinct names to
 * avoid a fatal redeclaration, so a helper two of them need belongs in the bootstrap.
 *
 * The frames after the first line add nothing an instance needs, and the cap keeps a runaway trace
 * out of the failure output. **Nothing here can hold a credential**: the stores return their value
 * on stdout, and what these children pass as an argument is the service key, which is not secret.
 *
 * @param  string  $errors  What the child wrote on stderr.
 * @return string The first non-empty line, capped, or a phrase saying there was none.
 */
function firstLineOf(string $errors): string
{
    foreach (preg_split('/\R/', $errors) ?: [] as $line) {
        $line = trim($line);

        if ($line !== '') {
            return \strlen($line) > 300 ? substr($line, 0, 300).'…' : $line;
        }
    }

    return '(nothing on stderr)';
}
