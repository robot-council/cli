<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\CredentialsServiceProvider;
use App\Support\Version;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => 'robot-council',

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value determines the "version" your application is currently running
    | in. You may want to follow the "Semantic Versioning" - Given a version
    | number MAJOR.MINOR.PATCH when an update happens: https://semver.org.
    |
    */

    'version' => Version::current(),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. This can be overridden using
    | the global command line "--env" option when calling commands.
    |
    | Production, because this application is installed through Composer rather than built into
    | a PHAR, so nothing else ever sets it. Laravel Zero adds its development commands -- `test`,
    | `app:build`, `make:command` and the rest -- in any other environment, and an installed copy
    | then offered commands that crash or write into `vendor/` (#241). A contributor reaches them
    | with `--env=development`.
    |
    */

    'env' => 'production',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [
        AppServiceProvider::class,
        CredentialsServiceProvider::class,
    ],

];
