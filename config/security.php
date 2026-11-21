<?php

declare(strict_types=1);

return [
    'frame_embeddable_routes' => [],

    'trusted_hosts' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('TRUSTED_HOSTS', 'laravel.test,localhost,127.0.0.1')),
    ))),
];
