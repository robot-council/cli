<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The application's own bindings.
 *
 * `final`, and with no protected methods, because Pest's `strict()` preset holds `App\` to the same
 * rules the package holds itself to.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register the application's services.
     */
    public function register(): void
    {
        //
    }
}
