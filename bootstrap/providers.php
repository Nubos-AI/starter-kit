<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    App\Providers\NubosTenantServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
