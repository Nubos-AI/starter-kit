<?php

declare(strict_types=1);

use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Enums\Webhooks\WebhookEventType;
use App\Models\TeamRecordAccessRule;
use App\Models\WebhookSubscription;
use App\Support\Authorization\CurrentTeamResolver;
use App\Support\Authorization\RowAccess\RecordAccessRuleCompiler;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use App\Support\Authorization\RowAccess\TeamChainProvider;
use App\Support\Tenancy\TenantBinder;
use App\Support\Webhooks\WebhookChangeDispatcher;
use App\Support\Webhooks\WebhookDeliveryStarter;
use App\Support\Webhooks\WebhookSubscriptionMatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\Doubles\StaticTeamAccessRuleSource;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $tenants = Mockery::mock(TenantBinder::class);
    $tenants->shouldReceive('runIfKnown')->andReturnUsing(static fn (string $tenantId, Closure $work): mixed => $work());
    app()->instance(TenantBinder::class, $tenants);

    $this->subscription = fn (string $seed, ?string $teamId = null): WebhookSubscription => ModelStub::make(WebhookSubscription::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenantId,
        'team_id' => $teamId,
        'object_type_id' => ModelStub::ulid('deals'),
    ]);

    $this->subscriptions = new EloquentCollection([
        ($this->subscription)('failing-subscription'),
        ($this->subscription)('healthy-subscription'),
    ]);

    $this->teamScoped = function (): void {
        $this->subscriptions = new EloquentCollection([
            ($this->subscription)('sales-subscription', ModelStub::ulid('sales-team')),
        ]);
    };

    $this->matcher = Mockery::mock(WebhookSubscriptionMatcher::class);
    $this->matcher->shouldReceive('match')->andReturnUsing(fn (): EloquentCollection => $this->subscriptions);

    $this->starter = Mockery::mock(WebhookDeliveryStarter::class);

    $this->ruleSource = new StaticTeamAccessRuleSource(new EloquentCollection([
        ModelStub::make(TeamRecordAccessRule::class, [
            'id' => ModelStub::ulid('north-only'),
            'tenant_id' => $this->tenantId,
            'team_id' => ModelStub::ulid('sales-team'),
            'object_type_id' => ModelStub::ulid('deals'),
            'filter_definition' => json_encode(['combinator' => 'and', 'conditions' => [['field' => 'region', 'operator' => 'eq', 'value' => 'north']]], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'inheritance' => TeamAccessRuleInheritance::Intersect->value,
        ]),
    ]));
    $this->compiler = AccessContext::recordRuleCompiler();
    $this->isWithinTeamAccess = true;
    $this->teamRows = [[
        'id' => ModelStub::ulid('sales-team'),
        'tenant_id' => $this->tenantId,
        'ancestor_team_ids' => json_encode([ModelStub::ulid('region-team')], JSON_THROW_ON_ERROR),
    ]];

    $this->accessRows = fn (string $sql): ?array => match (true) {
        str_starts_with($sql, 'select exists') => [['exists' => $this->isWithinTeamAccess]],
        str_contains($sql, 'from "teams"') => $this->teamRows,
        default => null,
    };

    $this->dispatcher = fn (): WebhookChangeDispatcher => new WebhookChangeDispatcher(
        $this->matcher,
        $this->starter,
        new TeamAccessRuleResolver(new TeamChainProvider(Mockery::mock(CurrentTeamResolver::class)), $this->ruleSource),
        app(RecordAccessRuleCompiler::class),
    );

    $tenantId = $this->tenantId;
    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => ($this->accessRows)($sql) ?? match (true) {
            str_contains($sql, 'from "outbox_events"') => [[
                'id' => ModelStub::ulid('outbox'),
                'tenant_id' => $tenantId,
                'sequence' => 7,
                'created_at' => '2026-09-01 08:00:00',
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => ModelStub::ulid('deal'),
                'tenant_id' => $tenantId,
                'object_type_id' => ModelStub::ulid('deals'),
                'version' => 2,
            ]],
            default => [],
        },
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('still starts the sibling subscriptions and then reports a failed start so the change is retried', function (): void {
    $started = [];
    $outage = new RuntimeException('temporal unreachable');

    $this->starter->shouldReceive('start')
        ->twice()
        ->andReturnUsing(function (WebhookSubscription $subscription) use (&$started, $outage): void {
            $started[] = (string) $subscription->getKey();

            if ($subscription->getKey() === ModelStub::ulid('failing-subscription')) {
                throw $outage;
            }
        });

    $dispatcher = ($this->dispatcher)();

    expect(fn () => $dispatcher->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']))
        ->toThrow(RuntimeException::class, 'temporal unreachable')
        ->and($started)->toBe([ModelStub::ulid('failing-subscription'), ModelStub::ulid('healthy-subscription')]);
});

it('finishes quietly once every subscription has been started', function (): void {
    $this->starter->shouldReceive('start')
        ->twice()
        ->withArgs(static fn (WebhookSubscription $subscription, mixed $record, WebhookEventType $type): bool => $type === WebhookEventType::RecordUpdated);

    $dispatcher = ($this->dispatcher)();

    $dispatcher->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']);
});

it('takes the delete or restore kind from the outbox entry and never from an audit row of the same version', function (bool $isDeletion, WebhookEventType $expected): void {
    $tenantId = $this->tenantId;

    StaticQueryConnection::install(
        fn (string $sql): array => ($this->accessRows)($sql) ?? match (true) {
            str_contains($sql, 'from "outbox_events"') => [[
                'id' => ModelStub::ulid('outbox'),
                'tenant_id' => $tenantId,
                'sequence' => 7,
                'version' => 3,
                'changed_field_keys' => json_encode(['deleted_at'], JSON_THROW_ON_ERROR),
                'payload' => json_encode(['deleted' => $isDeletion], JSON_THROW_ON_ERROR),
                'created_at' => '2026-09-01 08:00:00',
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => ModelStub::ulid('deal'),
                'tenant_id' => $tenantId,
                'object_type_id' => ModelStub::ulid('deals'),
                'version' => 3,
                'deleted_at' => $isDeletion ? null : '2026-09-01 08:00:00',
            ]],
            str_contains($sql, 'from "audit_entries"') => [[
                'new_value' => $isDeletion ? null : '"2026-09-01T08:00:00+00:00"',
            ]],
            default => [],
        },
    );

    $types = [];

    $this->starter->shouldReceive('start')
        ->twice()
        ->andReturnUsing(static function (WebhookSubscription $subscription, mixed $record, WebhookEventType $type) use (&$types): void {
            $types[] = $type;
        });

    (($this->dispatcher)())
        ->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 3, 7, ['deleted_at']);

    expect($types)->toBe([$expected, $expected]);
})->with([
    'a deletion' => [true, WebhookEventType::RecordDeleted],
    'a restore' => [false, WebhookEventType::RecordRestored],
]);

it('falls back to the current soft delete state when an outbox entry carries no lifecycle marker', function (bool $isTrashed, WebhookEventType $expected): void {
    $tenantId = $this->tenantId;

    StaticQueryConnection::install(
        fn (string $sql): array => ($this->accessRows)($sql) ?? match (true) {
            str_contains($sql, 'from "outbox_events"') => [[
                'id' => ModelStub::ulid('outbox'),
                'tenant_id' => $tenantId,
                'sequence' => 7,
                'version' => 3,
                'changed_field_keys' => json_encode(['deleted_at'], JSON_THROW_ON_ERROR),
                'payload' => null,
                'created_at' => '2026-09-01 08:00:00',
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => ModelStub::ulid('deal'),
                'tenant_id' => $tenantId,
                'object_type_id' => ModelStub::ulid('deals'),
                'version' => 3,
                'deleted_at' => $isTrashed ? '2026-09-01 08:00:00' : null,
            ]],
            default => [],
        },
    );

    $types = [];

    $this->starter->shouldReceive('start')
        ->twice()
        ->andReturnUsing(static function (WebhookSubscription $subscription, mixed $record, WebhookEventType $type) use (&$types): void {
            $types[] = $type;
        });

    (($this->dispatcher)())
        ->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 3, 7, ['deleted_at']);

    expect($types)->toBe([$expected, $expected]);
})->with([
    'a trashed record' => [true, WebhookEventType::RecordDeleted],
    'a live record' => [false, WebhookEventType::RecordRestored],
]);

it('delivers a system wide subscription without consulting any team access rule', function (): void {
    $this->starter->shouldReceive('start')->twice();

    ($this->dispatcher)()->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']);

    expect($this->ruleSource->askedFor)->toBe([])
        ->and($this->compiler->calls)->toBe([]);
});

it('withholds a change to a record outside the access rules of the subscription team chain', function (): void {
    ($this->teamScoped)();
    $this->isWithinTeamAccess = false;
    Log::spy();

    $this->starter->shouldNotReceive('start');

    ($this->dispatcher)()->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']);

    $check = $this->compiler->calls[0]['shape'] ?? null;

    expect($this->ruleSource->askedFor)->toBe([[ModelStub::ulid('sales-team'), ModelStub::ulid('region-team')]])
        ->and($this->compiler->calls)->toHaveCount(1)
        ->and($this->compiler->calls[0]['objectTypeIds'])->toBe([ModelStub::ulid('deals')])
        ->and($check)->toBeInstanceOf(QueryShape::class)
        ->and($check->isKeyedTo('custom_records', ModelStub::ulid('deal')))->toBeTrue()
        ->and($check->isScopedToTenant('custom_records', $this->tenantId))->toBeTrue();

    Log::shouldHaveReceived('info')
        ->with('Webhook delivery withheld; the record lies outside the access rules of the subscription team.', Mockery::on(
            static fn (array $context): bool => $context['subscription_id'] === ModelStub::ulid('sales-subscription')
                && $context['record_id'] === ModelStub::ulid('deal')
                && $context['team_id'] === ModelStub::ulid('sales-team'),
        ))
        ->once();
});

it('delivers a change to a record inside the access rules of the subscription team chain', function (): void {
    ($this->teamScoped)();
    $this->isWithinTeamAccess = true;

    $this->starter->shouldReceive('start')->once();

    ($this->dispatcher)()->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']);

    expect($this->compiler->calls)->toHaveCount(1);
});

it('delivers a team scoped change without a record check when no rule of the team chain covers the object type', function (): void {
    ($this->teamScoped)();
    $this->ruleSource = new StaticTeamAccessRuleSource(new EloquentCollection);

    $this->starter->shouldReceive('start')->once();

    ($this->dispatcher)()->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']);

    expect($this->compiler->calls)->toBe([]);
});

it('withholds every delivery of a subscription whose team is gone or lies outside the tenant', function (): void {
    ($this->teamScoped)();
    $this->teamRows = [];
    Log::spy();

    $this->starter->shouldNotReceive('start');

    ($this->dispatcher)()->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']);

    $lookup = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "teams"'));
    $shape = new QueryShape($lookup['sql'], $lookup['bindings']);

    expect($shape->isScopedToTenant('teams', $this->tenantId))->toBeTrue()
        ->and($shape->isKeyedTo('teams', ModelStub::ulid('sales-team')))->toBeTrue()
        ->and($shape->hidesSoftDeleted('teams'))->toBeTrue()
        ->and($this->compiler->calls)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->with('Webhook delivery withheld; the subscription team no longer exists in the tenant.', Mockery::on(
            static fn (array $context): bool => $context['subscription_id'] === ModelStub::ulid('sales-subscription')
                && $context['team_id'] === ModelStub::ulid('sales-team'),
        ))
        ->once();
});

it('withholds the deletion of a record the team could not see and judges it on the trashed row', function (): void {
    ($this->teamScoped)();
    $tenantId = $this->tenantId;

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => ($this->accessRows)($sql) ?? match (true) {
            str_contains($sql, 'from "outbox_events"') => [[
                'id' => ModelStub::ulid('outbox'),
                'tenant_id' => $tenantId,
                'sequence' => 7,
                'payload' => json_encode(['deleted' => true], JSON_THROW_ON_ERROR),
                'created_at' => '2026-09-01 08:00:00',
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => ModelStub::ulid('deal'),
                'tenant_id' => $tenantId,
                'object_type_id' => ModelStub::ulid('deals'),
                'version' => 3,
                'deleted_at' => '2026-09-01 08:00:00',
            ]],
            default => [],
        },
    );

    $this->isWithinTeamAccess = false;

    $this->starter->shouldNotReceive('start');

    ($this->dispatcher)()->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 3, 7, ['deleted_at']);

    $check = $this->compiler->calls[0]['shape'];

    expect($check->hidesSoftDeleted('custom_records'))->toBeFalse()
        ->and($check->isKeyedTo('custom_records', ModelStub::ulid('deal')))->toBeTrue();
});
