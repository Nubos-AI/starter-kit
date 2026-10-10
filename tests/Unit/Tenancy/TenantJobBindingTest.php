<?php

declare(strict_types=1);

use App\Jobs\Middleware\RebindTenantContext;
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

beforeEach(function (): void {
    /** @var callable(?string):object */
    $this->jobFor = static fn (?string $tenantId): object => new class($tenantId)
    {
        public function __construct(public ?string $tenantId) {}
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Context::forgetHidden('team_id');
});

it('rebinds a job to the tenant it carries', function (): void {
    AccessContext::forgetTenant();
    $jobTenantId = ModelStub::ulid('job-tenant');

    $shape = QueryShape::attemptedBy(fn () => (new RebindTenantContext)->handle(
        ($this->jobFor)($jobTenantId),
        static fn (object $job): null => null,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('tenants'))->toBeTrue()
        ->and($shape->hasBinding($jobTenantId))->toBeTrue();
});

it('falls back to the tenant in the hidden context when the job carries none', function (): void {
    AccessContext::forgetTenant();
    $contextTenantId = ModelStub::ulid('context-tenant');
    Context::addHidden('tenant_id', $contextTenantId);

    $shape = QueryShape::attemptedBy(fn () => (new RebindTenantContext)->handle(
        ($this->jobFor)(null),
        static fn (object $job): null => null,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($contextTenantId))->toBeTrue();
});

it('gives the previously bound tenant back even when the rebinding fails', function (): void {
    $ambient = AccessContext::tenant('ambient-tenant');

    QueryShape::attemptedBy(fn () => (new RebindTenantContext)->handle(
        ($this->jobFor)(ModelStub::ulid('job-tenant')),
        static fn (object $job): null => null,
    ));

    expect(app('current_tenant')->getKey())->toBe($ambient->getKey());
});

it('runs a job without any tenant on a query that blocks every row', function (): void {
    AccessContext::forgetTenant();
    $observed = null;

    (new RebindTenantContext)->handle(
        ($this->jobFor)(null),
        static function (object $job) use (&$observed): null {
            $observed = QueryShape::of(CustomRecord::class);

            return null;
        },
    );

    expect($observed)->not->toBeNull()
        ->and($observed->blocksEveryRow())->toBeTrue()
        ->and(app()->bound('current_tenant'))->toBeFalse();
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

    try {
        app(TenantBinder::class)->runWith($activityTenant, static function (): never {
            throw new RuntimeException('activity failed');
        });
    } catch (RuntimeException) {
        // the binder must clean up even on this path
    }

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
