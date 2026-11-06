<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Temporal\Client\ScheduleClient;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('resolves a temporal client without building its grpc service client until the first call', function (string $abstract, string $concrete): void {
    $client = app($abstract);

    expect($client)->toBeInstanceOf($concrete)
        ->and((new ReflectionClass($concrete))->isUninitializedLazyObject($client))->toBeTrue();
})->with([
    'workflow client' => [WorkflowClientInterface::class, WorkflowClient::class],
    'schedule client' => [ScheduleClientInterface::class, ScheduleClient::class],
]);

it('refuses a binding that builds something other than the temporal client on the first call', function (): void {
    app()->bind(WorkflowClientInterface::class, fn (): stdClass => new stdClass);
    app()->register(AppServiceProvider::class, force: true);

    $client = app(WorkflowClientInterface::class);

    expect(fn () => (new ReflectionClass(WorkflowClient::class))->initializeLazyObject($client))
        ->toThrow(InvalidArgumentException::class, 'Binding ['.WorkflowClientInterface::class.'] must build an instance of ['.WorkflowClient::class.'], got [stdClass].');
});
