<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\Tenant;
use App\Providers\AppServiceProvider;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Support\Facades\Context;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    AccessContext::forgetTenant();
    Context::forgetHidden('team_id');
});

it('does not let a job inherit the tenant of the job before it', function (): void {
    AccessContext::tenant('previous-tenant');
    Context::forgetHidden('tenant_id');

    (new AppServiceProvider(app()))->rebindTenantContext(Context::getFacadeRoot());

    expect(app()->bound('current_tenant'))->toBeFalse()
        ->and(QueryShape::of(CustomRecord::class)->blocksEveryRow())->toBeTrue();
});

it('hydrates the tenant of a job from the hidden context', function (): void {
    AccessContext::forgetTenant();
    $hydratedId = ModelStub::ulid('hydrated-tenant');
    Context::addHidden('tenant_id', $hydratedId);

    $shape = QueryShape::attemptedBy(
        fn () => (new AppServiceProvider(app()))->rebindTenantContext(Context::getFacadeRoot()),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->targets('tenants'))->toBeTrue()
        ->and($shape->hasBinding($hydratedId))->toBeTrue();
});

it('pins the work of an activity to the tenant it was handed', function (): void {
    $ambient = AccessContext::tenant('ambient-tenant');
    $activityTenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('activity-tenant')]);

    $inside = app(TenantBinder::class)->runWith(
        $activityTenant,
        static fn (): QueryShape => QueryShape::of(CustomRecord::class),
    );

    expect($inside->isScopedToTenant('custom_records', (string) $activityTenant->getKey()))->toBeTrue()
        ->and($inside->hasBinding((string) $ambient->getKey()))->toBeFalse()
        ->and(app('current_tenant')->getKey())->toBe($ambient->getKey())
        ->and(Context::getHidden('tenant_id'))->toBe((string) $ambient->getKey());
});

it('gives the ambient tenant back when the activity throws', function (): void {
    $ambient = AccessContext::tenant('ambient-tenant');
    $activityTenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('activity-tenant')]);

    expect(fn (): mixed => app(TenantBinder::class)->runWith($activityTenant, static function (): never {
        throw new RuntimeException('activity failed');
    }))->toThrow(RuntimeException::class, 'activity failed');

    expect(app('current_tenant')->getKey())->toBe($ambient->getKey())
        ->and(Context::getHidden('tenant_id'))->toBe((string) $ambient->getKey());
});

it('leaves no tenant behind when an activity ran without an ambient one', function (): void {
    AccessContext::forgetTenant();
    $activityTenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('activity-tenant')]);

    app(TenantBinder::class)->runWith($activityTenant, static fn (): null => null);

    expect(app()->bound('current_tenant'))->toBeFalse()
        ->and(Context::getHidden('tenant_id'))->toBeNull()
        ->and(QueryShape::of(CustomRecord::class)->blocksEveryRow())->toBeTrue();
});

it('runs work for the tenant that is already bound without loading it again', function (): void {
    $ambient = AccessContext::tenant('ambient-tenant');
    $handedTenant = null;

    $shape = QueryShape::attemptedBy(function () use ($ambient, &$handedTenant): void {
        $handedTenant = app(TenantBinder::class)->run(
            (string) $ambient->getKey(),
            static fn (Tenant $tenant): Tenant => $tenant,
        );
    });

    expect($shape)->toBeNull()
        ->and($handedTenant)->toBe($ambient)
        ->and(app('current_tenant'))->toBe($ambient)
        ->and(Context::getHidden('tenant_id'))->toBe((string) $ambient->getKey());
});

it('still loads the tenant when a different one is bound', function (): void {
    AccessContext::tenant('ambient-tenant');
    $otherTenantId = ModelStub::ulid('other-tenant');

    $shape = QueryShape::attemptedBy(fn () => app(TenantBinder::class)->run(
        $otherTenantId,
        static fn (Tenant $tenant): null => null,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('tenants'))->toBeTrue()
        ->and($shape->hasBinding($otherTenantId))->toBeTrue();
});
