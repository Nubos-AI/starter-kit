<?php

declare(strict_types=1);

use App\Actions\Engine\RollbackDefinitionAction;
use App\Actions\Promotion\RollbackPromotionRunAction;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Exceptions\Promotion\PromotionNotRollbackableException;
use App\Models\MaintenanceLock;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Promotion\BundleDiffer;
use App\Support\Promotion\PromotionBaselineStore;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\InMemoryPromotionBaselineStore;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant);

    $this->counterpart = 'sandbox:'.ModelStub::ulid('sandbox');

    $this->baselines = new InMemoryPromotionBaselineStore;
    app()->instance(PromotionBaselineStore::class, $this->baselines);

    $this->run = fn (array $overrides = []): PromotionRun => ModelStub::make(PromotionRun::class, [
        'id' => ModelStub::ulid('run'),
        'tenant_id' => $this->tenant->getKey(),
        'source_tenant_id' => ModelStub::ulid('source-tenant'),
        'triggered_by_id' => (string) $this->actor->getKey(),
        'counterpart_key' => $this->counterpart,
        'direction' => PromotionDirection::TenantToProduction->value,
        'status' => PromotionRunStatus::Completed->value,
        'snapshot_group_id' => ModelStub::ulid('snapshot-group'),
        'selection' => '[]',
        'conflict_decisions' => '[]',
        ...$overrides,
    ]);

    $this->call = static function (string $method, array $arguments): mixed {
        $action = app(RollbackPromotionRunAction::class);

        return (new ReflectionMethod($action, $method))->invokeArgs($action, $arguments);
    };

    $this->bundleOf = static fn (array $payloads): ConfigBundle => new ConfigBundle(
        new BundleManifest(1, 'Test', [ArtifactKind::ObjectTypes->value => count($payloads)]),
        array_map(
            static fn (array $payload, string $key): BundleArtifact => new BundleArtifact(ArtifactKind::ObjectTypes, $key, $payload),
            array_values($payloads),
            array_keys($payloads),
        ),
    );

    $this->lockedRegistry = static function (string $tenantId): MaintenanceLockRegistry {
        $lock = ModelStub::make(MaintenanceLock::class, [
            'id' => ModelStub::ulid('lock'),
            'tenant_id' => $tenantId,
            'status' => MaintenanceLockStatus::Active->value,
        ]);

        return new class($lock) extends MaintenanceLockRegistry
        {
            public function __construct(private readonly MaintenanceLock $lock) {}

            public function activeFor(string $tenantId): ?MaintenanceLock
            {
                return (string) $this->lock->getAttribute('tenant_id') === $tenantId ? $this->lock : null;
            }
        };
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('denies an acting user from another tenant before touching anything', function (): void {
    $run = ($this->run)();
    $foreignActor = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('foreign-actor'),
        'tenant_id' => ModelStub::ulid('other-tenant'),
    ]);

    $caught = null;

    $shape = QueryShape::attemptedBy(function () use ($run, $foreignActor, &$caught): void {
        try {
            app(RollbackPromotionRunAction::class)->execute($foreignActor, $run);
        } catch (AuthorizationException $exception) {
            $caught = $exception;
        }
    });

    expect($caught)->toBeInstanceOf(AuthorizationException::class)
        ->and($caught?->getMessage())->toBe('Der Handelnde gehört nicht zum Mandanten dieser Promotion.')
        ->and($shape)->toBeNull();
});

it('refuses a rollback while the tenant is under maintenance and leaves the database alone', function (): void {
    $run = ($this->run)();

    $action = new RollbackPromotionRunAction(
        app(RollbackDefinitionAction::class),
        app(ActingUserContext::class),
        app(ConfigBundleSerializer::class),
        app(BundleDiffer::class),
        $this->baselines,
        app(AdminArtifactAuditor::class),
        ($this->lockedRegistry)((string) $this->tenant->getKey()),
    );

    $caught = null;

    $shape = QueryShape::attemptedBy(function () use ($action, $run, &$caught): void {
        try {
            $action->execute($this->actor, $run);
        } catch (TenantUnderMaintenanceException $exception) {
            $caught = $exception;
        }
    });

    expect($caught)->toBeInstanceOf(TenantUnderMaintenanceException::class)
        ->and($caught?->getStatusCode())->toBe(503)
        ->and($caught?->tenantId())->toBe((string) $this->tenant->getKey())
        ->and($shape)->toBeNull();
});

it('refuses an already rolled back run', function (): void {
    $run = ($this->run)(['status' => PromotionRunStatus::RolledBack->value]);

    expect(fn (): mixed => ($this->call)('ensureRollbackable', [$run]))
        ->toThrow(PromotionNotRollbackableException::class, 'Diese Promotion wurde bereits zurückgenommen.');
});

it('refuses every run that is neither completed nor failed', function (): void {
    foreach ([PromotionRunStatus::Draft, PromotionRunStatus::Approved, PromotionRunStatus::Applying] as $status) {
        expect(fn (): mixed => ($this->call)('ensureRollbackable', [($this->run)(['status' => $status->value])]))
            ->toThrow(
                PromotionNotRollbackableException::class,
                'Nur eine abgeschlossene oder gescheiterte Promotion lässt sich zurücknehmen; diese hat keinen ausgeführten Stand, der sich zurückspielen ließe.',
            );
    }
});

it('refuses a run without a snapshot group', function (): void {
    $run = ($this->run)(['snapshot_group_id' => null]);

    expect(fn (): mixed => ($this->call)('ensureRollbackable', [$run]))
        ->toThrow(PromotionNotRollbackableException::class, 'Zu dieser Promotion gibt es keinen gesicherten Vorzustand, der sich zurückspielen ließe.');
});

it('accepts a completed and a failed run that carries a snapshot group', function (): void {
    foreach ([PromotionRunStatus::Completed, PromotionRunStatus::Failed] as $status) {
        expect(($this->call)('ensureRollbackable', [($this->run)(['status' => $status->value])]))->toBeNull();
    }
});

it('narrows the rollback to the artifact kinds the run selected, each kind once', function (): void {
    $run = ($this->run)(['selection' => (string) json_encode([
        ['kind' => ArtifactKind::ObjectTypes->value, 'key' => 'deal'],
        ['kind' => ArtifactKind::FieldDefinitions->value, 'key' => 'deal:amount'],
        ['kind' => ArtifactKind::ObjectTypes->value, 'key' => 'invoice'],
    ])]);

    expect(($this->call)('selectedKindsOf', [$run]))
        ->toBe([ArtifactKind::ObjectTypes, ArtifactKind::FieldDefinitions]);
});

it('forgets the baseline of every artifact the rollback moved and keeps an untouched one', function (): void {
    $movedIdentifier = ArtifactKind::ObjectTypes->identifierFor('deal');
    $untouchedIdentifier = ArtifactKind::ObjectTypes->identifierFor('invoice');

    $before = ($this->bundleOf)(['deal' => ['key' => 'deal', 'name' => 'Nach der Promotion'], 'invoice' => ['key' => 'invoice', 'name' => 'Rechnung']]);
    $after = ($this->bundleOf)(['deal' => ['key' => 'deal', 'name' => 'Vor der Promotion'], 'invoice' => ['key' => 'invoice', 'name' => 'Rechnung']]);

    $this->baselines->remember($this->counterpart, [
        $movedIdentifier => $before->find(ArtifactKind::ObjectTypes, 'deal')?->hash() ?? '',
        $untouchedIdentifier => $before->find(ArtifactKind::ObjectTypes, 'invoice')?->hash() ?? '',
    ]);

    $forgotten = ($this->call)('forgetMovedBaselines', [$this->counterpart, $before, $after]);

    expect($forgotten)->toBe([$movedIdentifier])
        ->and($this->baselines->hashesFor($this->counterpart))->toHaveKey($untouchedIdentifier)
        ->and($this->baselines->hashesFor($this->counterpart))->not->toHaveKey($movedIdentifier);
});

it('forgets nothing when the rollback moved nothing', function (): void {
    $identifier = ArtifactKind::ObjectTypes->identifierFor('deal');
    $bundle = ($this->bundleOf)(['deal' => ['key' => 'deal', 'name' => 'Unverändert']]);

    $this->baselines->rememberOne($this->counterpart, $identifier, $bundle->find(ArtifactKind::ObjectTypes, 'deal')?->hash() ?? '');

    $forgotten = ($this->call)('forgetMovedBaselines', [$this->counterpart, $bundle, $bundle]);

    expect($forgotten)->toBe([])
        ->and($this->baselines->hashesFor($this->counterpart))->toHaveKey($identifier);
});

it('refuses a member without the promotion permission before it locks the run', function (string $granted, bool $isAllowed): void {
    AccessContext::grant($granted);

    $actor = $this->actor;

    app()->instance(ActingUserContext::class, new class($actor) extends ActingUserContext
    {
        public function __construct(private readonly User $actor) {}

        public function run(string $tenantId, string $actingUserId, Closure $callback): void
        {
            $callback($this->actor);
        }
    });

    app()->instance(MaintenanceLockRegistry::class, new class extends MaintenanceLockRegistry
    {
        public function activeFor(string $tenantId): ?MaintenanceLock
        {
            return null;
        }
    });

    DB::shouldReceive('transaction')->andReturnUsing(static fn (Closure $callback): mixed => $callback());

    $run = ($this->run)();
    $caught = null;

    $shape = QueryShape::attemptedBy(function () use ($run, &$caught): void {
        try {
            app(RollbackPromotionRunAction::class)->execute($this->actor, $run);
        } catch (AuthorizationException $exception) {
            $caught = $exception;
        }
    });

    if ($isAllowed) {
        expect($caught)->toBeNull()
            ->and($shape?->targets('promotion_runs'))->toBeTrue()
            ->and($shape?->sql)->toContain('for update');

        return;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class)
        ->and($caught?->getMessage())->toBe(__('i18n.backend.actions.promotion.rollback_promotion_run_action.only_users_with_promotion_permission_may_revert_a_promotion'))
        ->and($caught?->getMessage())->not->toBe('i18n.backend.actions.promotion.rollback_promotion_run_action.only_users_with_promotion_permission_may_revert_a_promotion')
        ->and($shape)->toBeNull();
})->with([
    'a member holding another permission only' => ['promotions.view', false],
    'a member holding the promotion permission' => ['promotions.execute', true],
]);
