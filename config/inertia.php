<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        'enabled' => true,
        'url' => 'http://127.0.0.1:13714',
        'timeout' => (int) env('INERTIA_SSR_TIMEOUT', 5),
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    'history' => [

        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | DevTools
    |--------------------------------------------------------------------------
    |
    | Records one entry per request to disk so the DevTools Chrome extension may
    | read it back over HTTP. Recording resolves and redacts every prop of every
    | request, so it stays off until INERTIA_DEVTOOLS_ENABLED switches it on.
    |
    */

    'devtools' => [

        'enabled' => (bool) env('INERTIA_DEVTOOLS_ENABLED', false),

        'except' => ['telescope*', 'horizon*', '_inertia/devtools*'],

        'storage' => [

            'path' => storage_path('inertia-devtools'),

            'ttl' => (int) env('INERTIA_DEVTOOLS_TTL_HOURS', 24),

            'prune_interval' => (int) env('INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS', 300),

            'limit' => (int) env('INERTIA_DEVTOOLS_LIMIT', 100),

        ],

        'middleware' => ['web'],

        'gate' => env('INERTIA_DEVTOOLS_GATE'),

        'redact' => [

            'keys' => [
                'password',
                'password_confirmation',
                'current_password',
                'token',
                '_token',
                'access_token',
                'refresh_token',
                'secret',
                'client_secret',
                'api_key',
            ],

            'headers' => [
                'cookie',
                'set-cookie',
                'authorization',
                'proxy-authorization',
                'x-xsrf-token',
                'x-csrf-token',
            ],

        ],

    ],

];
