<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    |
    | "ttl_days" is how long an invitation link stays usable after it was sent.
    | "max_per_request" caps how many addresses a single invite submission may
    | carry, so one paste cannot fan out into an unbounded number of mails.
    |
    */
    'invitation' => [
        'ttl_days' => (int) env('USER_INVITATION_TTL_DAYS', 7),
        'max_per_request' => (int) env('USER_INVITATION_MAX_PER_REQUEST', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Address Changes
    |--------------------------------------------------------------------------
    |
    | A changed address only replaces the current one once its owner follows
    | the link mailed to it. "ttl_hours" is how long that link stays usable.
    |
    */
    'email_change' => [
        'ttl_hours' => (int) env('USER_EMAIL_CHANGE_TTL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Unverified Registrations
    |--------------------------------------------------------------------------
    |
    | A self registration whose address was never verified is purged together
    | with its tenant once it is older than "unverified_ttl_days", so it cannot
    | hold the address of somebody else. The Temporal schedule runs on
    | "purge_cron" in "purge_timezone" and releases at most "purge_batch_size"
    | registrations per run.
    |
    */
    'registration' => [
        'unverified_ttl_days' => (int) env('USER_UNVERIFIED_REGISTRATION_TTL_DAYS', 7),
        'purge_cron' => env('USER_UNVERIFIED_REGISTRATION_PURGE_CRON', '30 3 * * *'),
        'purge_timezone' => env('USER_UNVERIFIED_REGISTRATION_PURGE_TIMEZONE', 'UTC'),
        'purge_batch_size' => (int) env('USER_UNVERIFIED_REGISTRATION_PURGE_BATCH_SIZE', 200),
        'purge_activity_timeout' => (int) env('USER_UNVERIFIED_REGISTRATION_PURGE_ACTIVITY_TIMEOUT', 600),
    ],
];
