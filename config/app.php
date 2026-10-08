<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Debug Blacklist
    |--------------------------------------------------------------------------
    |
    | Key names Whoops must mask when APP_DEBUG renders an exception
    | page — a screenshotted debug dump is the classic follow-on
    | breach. Masked in both the env and _SERVER sections.
    |
    */

    'debug_blacklist' => [
        // Whoops masks by superglobal name — '_ENV', not 'env', or
        // the section silently protects nothing.
        '_ENV' => [
            'APP_KEY',
            'DB_PASSWORD',
            'REDIS_PASSWORD',
            'MAIL_PASSWORD',
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
        ],

        '_SERVER' => [
            'APP_KEY',
            'DB_PASSWORD',
            'REDIS_PASSWORD',
            'MAIL_PASSWORD',
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    | This is the STORAGE timezone — timestamps are written as UTC and
    | APP_TIMEZONE stays UTC. Which zone the UI renders them in is the
    | separate display_timezone below.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Display Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone the UI's server-rendered fallback text shows stored
    | UTC timestamps in, with the zone's own abbreviation attached
    | (AEST/AEDT/AWST/UTC/... — derived per moment, so a
    | DST-shifting zone labels itself correctly year-round). Viewers
    | with JavaScript see times in their own browser timezone instead
    | (resources/js/display-time.js converts the <time> elements
    | App\Support\DisplayTime emits); this zone covers the first
    | paint and no-JS rendering, and falls back to UTC on an invalid
    | value.
    |
    */

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Australia/Sydney'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
