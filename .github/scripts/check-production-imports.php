<?php

/**
 * Fail when a name `app/` imports is not satisfied by an install without dev dependencies.
 *
 * Run it from the root of a `composer install --no-dev` tree. Every test and every static
 * analysis run happens with dev dependencies installed, so a production dependency that
 * arrives only through a dev one is invisible to all of them: `illuminate/http` reached
 * `vendor/` only through `larastan/larastan` for four releases (robot-council/cli#237).
 *
 * It reads the top-level `use` imports of every file under `app/` and asks the autoloader
 * for each. A class the container resolves by a string, or one written fully qualified
 * without an import, is not seen; the smoke step beside this script in the workflow covers
 * the HTTP client that way. Imports inside a braced `namespace X { }` block are not read
 * either, and no file under `app/` uses one.
 */

declare(strict_types=1);

$root = getcwd();

if ($root === false || ! is_file($root.'/vendor/autoload.php') || ! is_dir($root.'/app')) {
    fwrite(STDERR, "Run this from the root of an installed checkout.\n");

    exit(2);
}

require $root.'/vendor/autoload.php';

/**
 * The top-level imports of one file, as kind and name.
 *
 * @return list<array{string, string}>
 */
function importsOf(string $path): array
{
    $imports = [];
    $depth = 0;
    $tokens = PhpToken::tokenize((string) file_get_contents($path));
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];

        // A `use` inside braces is a trait use or a closure's captures, not an import.
        if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;
        } elseif ($token->text === '}') {
            $depth--;
        }

        if ($depth !== 0 || ! $token->is(T_USE)) {
            continue;
        }

        // Collect the statement's text up to its semicolon, braces included.
        $statement = '';

        for ($index++; $index < $count && $tokens[$index]->text !== ';'; $index++) {
            if (! $tokens[$index]->isIgnorable()) {
                $statement .= $tokens[$index]->text.' ';
            }
        }

        $kind = 'class';

        if (preg_match('/^(function|const)\s+(.*)$/s', trim($statement), $match) === 1) {
            [$kind, $statement] = [$match[1], $match[2]];
        }

        // Expand a group import, `A\{B, C}`, into `A\B` and `A\C`.
        $names = [];

        if (preg_match('/^(.*?)\\\\?\s*\{(.*)\}\s*$/s', trim($statement), $group) === 1) {
            // A trailing comma leaves an empty member, which is not an import.
            foreach (array_filter(array_map(trim(...), explode(',', $group[2])), static fn (string $member): bool => $member !== '') as $member) {
                $names[] = rtrim(trim($group[1]), '\\').'\\'.$member;
            }
        } else {
            $names = explode(',', $statement);
        }

        foreach ($names as $name) {
            // Drop an alias and the spaces the token join put in.
            $name = str_replace(' ', '', (string) preg_replace('/\s+as\s+\w+\s*$/i', '', trim($name)));

            if ($name !== '') {
                $imports[] = [$kind, ltrim($name, '\\')];
            }
        }
    }

    return $imports;
}

/**
 * Whether the autoloader, or the runtime, can supply the name.
 */
function resolves(string $kind, string $name): bool
{
    return match ($kind) {
        'function' => function_exists($name),
        'const' => defined($name),
        default => class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name),
    };
}

$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)),
    '/\.php$/',
);

$checked = 0;
$scanned = 0;
$missing = [];

foreach ($files as $file) {
    $scanned++;

    foreach (importsOf($file->getPathname()) as [$kind, $name]) {
        $checked++;

        if (! resolves($kind, $name)) {
            $missing[] = sprintf('%s %s  (%s)', $kind, $name, substr($file->getPathname(), strlen($root) + 1));
        }
    }
}

// Printed before the verdict, so a scan that saw nothing cannot read as a clean one.
printf("Scanned %d files under app/, %d imports.\n", $scanned, $checked);

if ($checked === 0) {
    fwrite(STDERR, "No imports found, so nothing was checked.\n");

    exit(2);
}

if ($missing !== []) {
    fwrite(STDERR, "Not satisfied without dev dependencies:\n  ".implode("\n  ", $missing)."\n");

    exit(1);
}

echo "Every import resolves.\n";
