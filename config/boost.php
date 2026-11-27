<?php

declare(strict_types=1);

return [
    'enabled' => env('BOOST_ENABLED', env('APP_ENV') === 'local'),
];
