<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravel\Octane\Swoole\ServerProcessInspector;
use Laravel\Octane\Swoole\SwooleExtension;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->wayfinderRuns = new ArrayObject;

    $runs = $this->wayfinderRuns;
    Artisan::command('wayfinder:generate {--path=} {--skip-actions} {--skip-routes} {--with-form}', function () use ($runs): int {
        $runs[] = $this->option('with-form') ? 'with-form' : 'plain';

        return 0;
    });
});

it('regenerates the route helpers so an installed module brings its helpers and a removed one takes them along', function (): void {
    config(['octane.server' => null]);

    $this->artisan('modules:refresh')->assertSuccessful();

    expect($this->wayfinderRuns->getArrayCopy())->toBe(['with-form']);
});

it('refreshes workers only when the configured web server is running', function (bool $running): void {
    config(['octane.server' => 'swoole']);
    $this->mock(SwooleExtension::class)->shouldReceive('isInstalled')->andReturnTrue();
    $inspector = $this->mock(ServerProcessInspector::class);
    $inspector->shouldReceive('serverIsRunning')->once()->andReturn($running);
    if ($running) {
        $inspector->shouldReceive('reloadServer')->once();
    } else {
        $inspector->shouldNotReceive('reloadServer');
    }
    $this->artisan('modules:refresh')->assertSuccessful();
})->with([true, false]);

it('skips the worker reload when this PHP has no Swoole extension', function (): void {
    config(['octane.server' => 'swoole']);
    $this->mock(SwooleExtension::class)->shouldReceive('isInstalled')->andReturnFalse();
    $this->mock(ServerProcessInspector::class)->shouldNotReceive('serverIsRunning');

    $this->artisan('modules:refresh')->assertSuccessful();
});
