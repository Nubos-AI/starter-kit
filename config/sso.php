<?php

declare(strict_types=1);

return [
    'oidc' => [
        'clock_leeway_seconds' => (int) env('SSO_CLOCK_LEEWAY_SECONDS', 60),
        'http_timeout_seconds' => (int) env('SSO_HTTP_TIMEOUT_SECONDS', 10),
        'discovery_cache_seconds' => (int) env('SSO_DISCOVERY_CACHE_SECONDS', 3600),
        'jwks_cache_seconds' => (int) env('SSO_JWKS_CACHE_SECONDS', 3600),
        'pending_login_ttl_seconds' => (int) env('SSO_PENDING_LOGIN_TTL_SECONDS', 600),
        'scopes' => ['openid', 'email', 'profile'],
        'start_attempts_per_minute' => (int) env('SSO_START_ATTEMPTS_PER_MINUTE', 10),
    ],

    'scim' => [
        'requests_per_minute' => (int) env('SCIM_REQUESTS_PER_MINUTE', 120),
        'default_page_size' => (int) env('SCIM_DEFAULT_PAGE_SIZE', 100),
        'max_page_size' => (int) env('SCIM_MAX_PAGE_SIZE', 200),
        'token_prefix' => 'scim_',
    ],
];
