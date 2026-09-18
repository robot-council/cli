<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Credentials\Credentials;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the credential store resolver, so a test can swap the whole stack for a fake one.
 *
 * Bound rather than constructed where it is used, because the alternative is a command that can
 * only be tested by writing to the developer's real Keychain.
 */
final class CredentialsServiceProvider extends ServiceProvider
{
    /**
     * Register the resolver.
     */
    public function register(): void
    {
        $this->app->singleton(Credentials::class, fn (): Credentials => new Credentials(Credentials::candidates()));
    }
}
