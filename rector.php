<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveDuplicatedReturnSelfDocblockRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;

return RectorConfig::configure()
    // The same paths PHPStan analyzes.
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/config',
        __DIR__.'/tests',
        __FILE__,
    ])
    // Keep the cache inside this tree (`build/` is gitignored). Rector's default is a directory
    // under the system temp directory shared by every checkout on the machine, so two worktrees
    // would read each other's cached results.
    ->withCache(cacheDirectory: __DIR__.'/build/rector')
    ->withSkip([
        // `readonly` is a semantic change, not a style one: it breaks framework code that mutates
        // after construction, cloning, and mocking. Apply it deliberately, not on pain of a red
        // check.
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,

        // These delete docblock tags that the `php-documentation` skill requires: an inline
        // `@var` pinning a value that arrives untyped, and `@return $this` beside `: static`.
        RemoveUselessVarTagRector::class,
        RemoveDuplicatedReturnSelfDocblockRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    // The PHP sets for the version `composer.json` requires.
    ->withPhpSets()
    // The Laravel sets for the installed framework version.
    ->withComposerBased(laravel: true)
    // Pest's own rules for test code: idiomatic expectations, and no leftover `->only()` or debug
    // expectations. They match Pest calls only, so they leave `app/` alone.
    ->withSets([PestSetList::CODING_STYLE])
    // Promote inline fully qualified class names to `use` imports, leaving global short classes
    // alone. Pint owns removing unused imports, because Rector's removal does not treat a class
    // named only inside a docblock `{@see}` tag as used.
    ->withImportNames(importShortClasses: false, removeUnusedImports: false);
