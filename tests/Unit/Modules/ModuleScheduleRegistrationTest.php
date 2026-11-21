<?php

declare(strict_types=1);

use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use App\Support\Temporal\RecordProcessingScheduleRegistrar;
use Illuminate\Contracts\Console\Kernel;
use Tests\Fixtures\Modules\nubos\example\src\RegisterExampleSchedulesCommand;
use Tests\Support\Doubles\FixtureModuleCatalog;
use Tests\Support\Doubles\StaticModuleRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $registrar = Mockery::mock(RecordProcessingScheduleRegistrar::class);
    $registrar->shouldReceive('register')->once();
    app()->instance(RecordProcessingScheduleRegistrar::class, $registrar);
    app()->instance(ModuleCatalog::class, new FixtureModuleCatalog);
    app(Kernel::class)->registerCommand(new RegisterExampleSchedulesCommand);
    config(['modules.schedule_commands' => ['example:register-schedules']]);
});

afterEach(function (): void {
    app()->forgetInstance(RecordProcessingScheduleRegistrar::class);
    app()->forgetInstance(ModuleCatalog::class);
    app()->forgetInstance(ModuleRegistry::class);
    Mockery::close();
});

it('does not create the schedules of a module that is unregistered while its code is still installed', function (): void {
    app()->instance(ModuleRegistry::class, new StaticModuleRegistry);

    $this->artisan('records:register-schedules')
        ->doesntExpectOutputToContain('Example schedules registered.')
        ->assertSuccessful();
});

it('creates the schedules of a registered module', function (): void {
    app()->instance(ModuleRegistry::class, new StaticModuleRegistry(['nubos/example']));

    $this->artisan('records:register-schedules')
        ->expectsOutputToContain('Example schedules registered.')
        ->assertSuccessful();
});
