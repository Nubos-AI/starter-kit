<?php

declare(strict_types=1);

use App\Providers\ApiRateLimitServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\TranslationServiceProvider;

return [
    AppServiceProvider::class,
    ApiRateLimitServiceProvider::class,
    FortifyServiceProvider::class,
    TranslationServiceProvider::class,
];
