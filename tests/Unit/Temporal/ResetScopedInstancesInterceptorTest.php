<?php

declare(strict_types=1);

use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Temporal\ResetScopedInstancesInterceptor;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Header;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->input = new ActivityInput(EncodedValues::empty(), Header::empty());
    $this->interceptor = app(ResetScopedInstancesInterceptor::class);
});

it('keeps the scoped resolvers the activity was constructed with for its whole run and hands the next activity fresh ones', function (string $abstract): void {
    $injectedIntoActivity = app($abstract);

    $resolvedDuringRun = $this->interceptor->handleActivityInbound($this->input, static fn (): object => app($abstract));

    expect($resolvedDuringRun)->toBe($injectedIntoActivity)
        ->and(app($abstract))->not->toBe($injectedIntoActivity);
})->with([
    'team access rules' => [TeamAccessRuleResolver::class],
    'field visibility' => [FieldVisibilityResolver::class],
    'object type registry' => [ObjectTypeRegistry::class],
    'row access enforcement' => [RowAccessEnforcement::class],
]);

it('forgets the scoped instances even when the activity fails', function (): void {
    $seenByActivity = null;

    expect(fn (): mixed => $this->interceptor->handleActivityInbound($this->input, static function () use (&$seenByActivity): never {
        $seenByActivity = app(TeamAccessRuleResolver::class);

        throw new RuntimeException('activity failed');
    }))->toThrow(RuntimeException::class, 'activity failed');

    expect(app(TeamAccessRuleResolver::class))->not->toBe($seenByActivity);
});

it('returns the result of the activity unchanged', function (): void {
    expect($this->interceptor->handleActivityInbound($this->input, static fn (): string => 'done'))->toBe('done');
});

it('is registered as a worker interceptor', function (): void {
    expect(config('temporal.interceptors'))->toContain(ResetScopedInstancesInterceptor::class);
});
