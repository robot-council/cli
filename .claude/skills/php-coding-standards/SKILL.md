---
name: php-coding-standards
description: >-
  Package-wide PHP coding standards: type hints, method/function call formatting (multi-line
  parameters at 2+ arguments, PSR-12 `elseif` placement), arrow vs anonymous functions and
  first-class callables, `sprintf()`/concatenation for literal+variable strings, PHP 8.3+ typed
  class constants, imports and Pint's import ordering, leading-backslash native function calls,
  constructor property promotion, contract vs concrete type hints, avoiding "magic" macros /
  `__call`, SRP, DRY, and long-running-loop `unset()` / `gc_collect_cycles()` discipline.
  Activate whenever writing or editing first-party PHP under `src/`, `config/`, `database/`, or
  `tests/` — service providers, facades, console commands, closures/callables, conditionals, and
  any refactor that touches method signatures, properties, imports, or constants.
---

# PHP Coding Standards

This skill encodes the package's always-on PHP conventions so new and refactored code matches
them and passes Pint, Rector, and PHPStan cleanly. Pint runs with the Laravel preset (there is no
`pint.json`) and Rector runs with [`rector.php`](../../../rector.php); each enforces some of these
mechanically, and CI fails when either would change a file. Everything marked as a convention is
applied by hand, because nothing else checks it.

**Every PHP file declares `declare(strict_types=1);`** after the opening tag. Rector's
`SafeDeclareStrictTypesRector` adds it where it is safe to.

## 1. Type hints (always required where the parent allows)

- **Parameters**: type hint every parameter when not blocked by a parent signature.
- **Return types**: always declare, including `: void`.
- **Properties**: type all class and trait properties.
- **Nullable**: use `?Type`; **union** types where appropriate.
- **PHPDoc must agree with the signature.** If `@param Collection $items` is documented, put
  `Collection $items` on the parameter and import the class (see §6).
- **Overrides**: check the parent signature first. You may widen (add type hints the parent
  omits) and use covariant return types, but never strip or narrow the parent's contract.
- When the parent forbids a type hint, still add a complete PHPDoc `@param` with a description.

```php
/**
 * Build the summary line for a finished run.
 *
 * @param  string  $name  The run's display name.
 * @param  bool  $includeTiming  Whether to append the elapsed time.
 * @return string The formatted summary line.
 */
public function summarize(
    string $name,
    bool $includeTiming = false
): string {
    // ...
}
```

## 2. Method / function call formatting (2+ params → multi-line)

A convention; Pint's `method_argument_space` is set to ignore multi-line layout, so it neither
enforces nor undoes this. When a call **or** definition has 2+ parameters, split it:

- One parameter per line.
- Indent parameters one level (4 spaces).
- For **calls**, closing `)` on its own line, aligned with the start of the call.
- For **definitions**, closing `)`, return type, and opening `{` go together on one line:
  `): string {`.
- This applies inside `if` / `elseif` conditions too — split the inner call from the
  conditional's parentheses.

```php
$this->reporter->record(
    $name,
    $duration,
    'council'
);

if (
    preg_match(
        '/^\'([^\']+)\'$/',
        $part,
        $matches
    )
) {
    // ...
}
```

## 3. `if` / `elseif` / `else` placement (PSR-12 § 5.1)

`} elseif {` / `} else {` on the same line as the preceding closing brace. Pint enforces this
(`elseif` turns `else if` into `elseif`; `control_structure_continuation_position` pulls it onto
the brace's line).

```php
if ($foo === 'bar') {
    // ...
} elseif ($foo === 'baz') {
    // ...
} else {
    // ...
}
```

Always use curly braces, even for single-line bodies.

## 4. Arrow functions vs anonymous functions vs first-class callables

Rector enforces the rewrites below: `ClosureToArrowFunctionRector` turns a single-expression closure into `fn`, and `ArrowFunctionDelegatingCallToFirstClassCallableRector` turns a pure pass-through into `$obj->method(...)` when it can resolve the method. The judgment of when a closure should stay a `function` is still yours.

- **Prefer `fn`** for single-expression closures with no scope mutation and no complex `use`
  clause.
- **Keep `function (...)`** when you need multiple statements, scope mutation, or when a `use`
  clause materially improves clarity.
- **Prefer first-class callable syntax `$obj->method(...)`** (or `Class::method(...)`) over both
  `Closure::fromCallable([$obj, 'method'])` and `fn ($x) => $obj->method($x)` when the closure is
  a pure pass-through to a single named method.

```php
// Preferred: forwards exactly to the method
$items->each($service->handle(...));

// Avoid: redundant wrappers with identical behavior
$items->each(Closure::fromCallable([$service, 'handle']));
$items->each(fn ($item) => $service->handle($item));
```

Keep an `fn` / `function` when the body does **more** than a straight pass-through: nested-data
reads (`$item['field'] ?? []`), container lookups (`$this->app->make(...)`), branching, or
argument transforms.

## 5. Literal + variable strings → `sprintf()` / concatenation

A convention for the interpolation half — neither Pint nor the Rector sets in `rector.php` rewrite `"{$var}"` interpolation:

- **`sprintf()`** for a format string with one or more embedded placeholders — `%s` for strings,
  `%d` for integers. A literal `\n` lifts to a trailing `PHP_EOL` argument (single-quoted
  formats cannot carry an escape).
- **`.`-concatenation** for a single variable appended to a leading literal. Pint's
  `concat_space` removes the spaces around `.`.

```php
// Preferred
return sprintf('prefix_%s_%s', $handle, $hash);
$key = sprintf('run:%s:summary', $id);
$message = 'Output file: '.$outputPath;

// Avoid
return "prefix_{$handle}_{$hash}";
$message = "Output file: {$outputPath}";
```

`sprintf()` is likewise the right call for casts and non-string values and for line-wrapped long
strings; `implode()` for joining lists.

## 6. Imports

- **Never** use a fully qualified class name inline (`\Illuminate\Support\Str::lower(...)`).
  Add a `use` statement. Pint's `fully_qualified_strict_types` (with `import_symbols`) imports
  FQCNs it finds in type declarations, `new` expressions, and static calls, but write the import
  yourself rather than relying on it.
- Pint's `ordered_imports` sorts imports alphabetically in the group order **const, class,
  function**, and `blank_line_between_import_groups` separates the groups; `no_unused_imports`
  removes unused ones.
- One import per statement (`single_import_per_statement`).
- Use aliases to disambiguate name collisions — for example, a facade and a class that share a
  short name — rather than importing both under the same name.

```php
use const PHP_EOL;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Support\Str;
use RobotCouncil\RobotCouncilServiceProvider;

use function Laravel\Prompts\confirm;
```

### Leading-backslash native function calls

A convention; Pint's Laravel preset has no `native_function_invocation` fixer, so
`vendor/bin/pint --dirty` passes either way. Call **compiler-optimized native functions** with a
leading `\` — `\is_array(...)`, `\is_string(...)`, `\count(...)`, `\sprintf(...)`,
`\in_array(...)`, etc. PhpStorm flags the bare form with inspection **PHP6616** ("Special
function … should be called in global namespace to allow compiler optimization").

- Only the **compiler-optimized subset** takes the prefix. Functions outside it stay unprefixed:
  `method_exists`, `json_decode`, `file_get_contents`, `array_values`, `array_keys`, `basename`,
  `mkdir`, `is_dir`, and Laravel helpers like `config()`. When unsure, trust the PHP6616 hint —
  it fires only on functions that want the prefix.

## 7. Constructors

- Use **constructor property promotion**:
  `public function __construct(private Factory $filesystems) {}`. Rector enforces it
  (`ClassPropertyAssignToConstructorPromotionRector`).
- Disallow an empty zero-parameter `__construct()` unless it is `private` (factory pattern).

## 8. Typed class constants (PHP 8.3+; the package requires `^8.4`)

Type literal-valued constants so static analysis can infer without `@var` workarounds. Rector enforces it (`AddTypeToConstRector`).

```php
private const string CACHE_KEY = 'robot-council.summary';
protected const int DEFAULT_LIMIT = 25;
```

Skip typing only when the value is genuinely mixed.

## 9. Contract vs concrete types

Hint **the narrowest type that declares what your code actually calls** and that callers can
supply. Laravel's contracts (`Illuminate\Contracts\...`) are the usual choice for a package,
because a consumer's container can bind its own implementation.

For example, `Illuminate\Contracts\Filesystem\Factory::disk()` returns
`Illuminate\Contracts\Filesystem\Filesystem`, which declares `get()`, `put()`, `exists()`, and the
like but **not** `url()`. `url()` is declared on `Illuminate\Contracts\Filesystem\Cloud`
(which extends `Filesystem`) and implemented by the concrete
`Illuminate\Filesystem\FilesystemAdapter`. So:

```php
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;

public function publicUrl(
    Factory $filesystems,
    string $path
): string {
    $disk = $filesystems->disk('public');

    if (! $disk instanceof Cloud) {
        throw new RuntimeException('The public disk must be a cloud disk to build a URL.');
    }

    return $disk->url($path);
}
```

A method that only reads and writes files takes `Filesystem`; one that builds URLs takes (or
narrows to) `Cloud`; neither needs `FilesystemAdapter`. Narrow with `if` / `throw` over a
misleading `@var` on the return value. **Not `assert()`:** Pest's `security()` preset bans it in
the package's namespaces, and a failing `if` says what went wrong where `assert()` is compiled
out under `zend.assertions=-1`. Larastan special-cases some facade returns — it types `Storage::disk()` as `FilesystemAdapter` — so `url()` resolves through the
facade while the same call through an injected `Factory` has only `Filesystem` to go on.

### Choose a contract when

- The method body only calls methods on the contract or a smaller interface.
- Consumers or tests should be able to supply their own implementation.
- A parent interface already declares the contract type.

### Choose the concrete class when

- The body calls methods absent from every contract.
- Analyzers report "unknown method" on a contract-typed variable and no narrower contract
  declares it.
- You have already guarded type and null, and the rest of the method assumes the concrete type.

## 10. Avoid "magic" (macros, `__call`, undocumented helpers)

PHPStan and PhpStorm only understand **declared** APIs. Prefer patterns that are typed or
documented on the class you are calling.

Common magic sources to avoid: macros on `Response`, `RedirectResponse`, `Collection`, or query
builders; fluent chains on contract-typed returns; `__call` / `Macroable`; facade methods missing
from the facade's `@method` PHPDoc.

**Preferred alternatives (in order):**

1. Use a first-class API on the same object — e.g. `session()->flash('success', $message);
   return back();` rather than an undeclared `back()->withSuccess($message)`.
2. Wrap the behavior in your own small class or trait with real method signatures.
3. Narrow types with `if (! $x instanceof Concrete) { throw … }` before calling methods only the
   concrete type declares. The `security()` preset bans `assert()`.
4. Last resort: a dedicated PHPStan stub or extension, with a comment near the call site
   explaining why.

## 11. Single Responsibility Principle (SRP)

Each class has one well-defined purpose — one reason to change. Split when a class mixes concerns
(e.g. compiling a report **and** printing it → `ReportCompiler` + `ReportPrinter`). Name classes
after their single responsibility.

## 12. Don't Repeat Yourself (DRY)

- Centralize repeated logic, validation rules, configuration, and business rules.
- Use abstractions (functions, classes) — but balance against the AHA principle: three similar
  lines are better than a premature abstraction.
- Keep a single source of truth for constants and config (a file under `config/`, once the package ships one).

## 13. `unset()` and `gc_collect_cycles()` — only where memory pressure is real

- **Do not** add end-of-method `unset()` to ordinary console commands, service providers, queued
  jobs, or small services. Locals disappear when the method returns; the noise diverges from
  ecosystem style.
- **Do** consider `unset()` inside **tight loops** in long-running processes (large imports,
  batch transforms, streaming pipelines) where iterations hold large arrays, model graphs, or DOM
  trees not needed in the next iteration.
- Only unset names that exist on the current path. Never unset a variable still used in the
  `return` expression.
- Call `gc_collect_cycles()` only when profiling shows retained memory or circular graphs in a
  long-running loop — never by default.

When `unset()` has multiple arguments, format it multi-line per §2:

```php
unset(
    $largeIntermediate,
    $parsedChunk
);
```

## 14. Architecture presets (`tests/ArchTest.php`)

Pest's `php()`, `security()`, and `strict()` presets run over the package's autoloaded namespace
(`src/`), not `tests/`, and fail the suite on:

- **Classes** — every class `final`, no `abstract` classes, and no `protected` methods. When a
  parent declares a method `protected` that you must override (for example a facade's
  `getFacadeAccessor()`), widen it to `public`, which PHP allows, and add the file to the
  `MakeInheritedMethodVisibilitySameAsParentRector` skip in `rector.php`, which would otherwise
  narrow it back. `final` means consumers cannot extend a class, so an extension point has to be
  designed in: a contract, a container binding, or configuration.
- **Strictness** — `declare(strict_types=1)` and strict comparison (`===`, `!==`, never `==`).
- **Functions** — no debugging or output functions (`dd`, `dump`, `ray`, `var_dump`, `print_r`,
  `var_export`, `echo`, `print`, `die`, and more), no `sleep` or `usleep`, and none of the
  functions `security()` bans (`assert`, `md5`, `sha1`, `rand`, `mt_rand`, `uniqid`, `eval`,
  `exec`, `shell_exec`, `system`, `unserialize`, `extract`, and more). The full lists are in
  `vendor/pestphp/pest/src/ArchPresets/`.

## 15. Verification

- Run `vendor/bin/pint --dirty` before finalizing; it fixes the files with uncommitted changes.
  (`--test` only reports and fixes nothing.) Formatting locally matters here: the CI `pint` job
  runs `vendor/bin/pint --test`, so an unformatted file fails `ci-passed` and blocks the merge.
- Run the affected tests with `vendor/bin/pest --compact tests/ExampleTest.php` or
  `vendor/bin/pest --compact --filter='test name'`.
- Run `composer refactor` before finalizing, then review its diff; CI's `rector` job runs
  `vendor/bin/rector --dry-run` and fails when Rector would change a file.
- Run `composer analyse` when signatures, types, or contracts changed.
