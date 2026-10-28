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
];
