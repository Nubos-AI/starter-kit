<?php

declare(strict_types=1);

use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Support\Facades\Route;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('names only core routes as non-writing before any package adds its own', function (): void {
    $coreNames = (new ReflectionProperty(MaintenanceLockRegistry::class, 'nonWritingRouteNames'))->getDefaultValue();

    $packageRoutes = array_values(array_filter(
        $coreNames,
        static fn (string $name): bool => str_starts_with((string) Route::getRoutes()->getByName($name)?->getControllerClass(), 'Nubos\\'),
    ));

    expect($packageRoutes)->toBe([]);
});
