<?php

declare(strict_types=1);

use App\Models\CustomRecord;

return [

    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    'prefix' => env('SCOUT_PREFIX', ''),

    'queue' => env('SCOUT_QUEUE', true),

    'after_commit' => true,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => false,

    'identify' => env('SCOUT_IDENTIFY', false),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            CustomRecord::class => [
                'filterableAttributes' => [
                    'tenant_id',
                    'team_id',
                    'owner_id',
                    'object_type_id',
                ],
            ],
        ],
    ],

];
