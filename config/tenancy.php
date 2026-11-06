<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Welcome page per tenant
    |--------------------------------------------------------------------------
    |
    | "addressing" decides how a visitor reaches the welcome page of a tenant:
    | "path" serves it under /{slug} (local development), "host" under the
    | subdomain {slug}.{base_domain} or under an own domain of the tenant that
    | points at the platform through a CNAME record.
    |
    */
    'welcome' => [
        'addressing' => env('TENANT_WELCOME_ADDRESSING', env('APP_ENV') === 'local' ? 'path' : 'host'),
        'base_domain' => env('TENANT_BASE_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
    ],

    'edge' => [
        'trusted_proxies' => env('TRUSTED_PROXIES', ''),
        'tls_ask_token' => env('TENANT_TLS_ASK_TOKEN', ''),
    ],
];
