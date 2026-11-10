<?php

declare(strict_types=1);

use App\Actions\Promotion\ExecutePromotionRunAction;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Exceptions\Promotion\PromotionNotExecutableException;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Models\PromotionRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->action = static fn (): ExecutePromotionRunAction => app(ExecutePromotionRunAction::class);

    $this->run = fn (array $overrides = []): PromotionRun => ModelStub::make(PromotionRun::class, [
        'id' => ModelStub::ulid('run'),
        'tenant_id' => $this->tenant->getKey(),
        'source_tenant_id' => ModelStub::ulid('source-tenant'),
        'triggered_by_id' => ModelStub::ulid('trigger'),
        'counterpart_key' => 'sandbox:'.ModelStub::ulid('source-tenant'),
        'direction' => PromotionDirection::TenantToProduction->value,
        'status' => PromotionRunStatus::Approved->value,
        'selection' => '[]',
        'conflict_decisions' => '[]',
        ...$overrides,
    ]);

    $this->call = static function (string $method, array $arguments): mixed {
        $action = app(ExecutePromotionRunAction::class);

        return (new ReflectionMethod($action, $method))->invokeArgs($action, $arguments);
    };

    $this->conflictOf = static fn (ArtifactKind $kind, string $key, DiffState $state): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: $state,
        sourceHash: hash('sha256', 'source'),
        targetHash: hash('sha256', 'target'),
        baselineHash: null,
        changedPaths: [],
    );

    $this->decisionOf = static fn (ArtifactKind $kind, string $key): ConflictDecision => new ConflictDecision(
        kind: $kind,
        key: $key,
        resolution: ConflictResolution::TakeSource,
        decidedById: ModelStub::ulid('decider'),
        decidedAt: CarbonImmutable::now(),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('claims only an approved run of its own tenant', function (): void {
    $run = ($this->run)();

    $shape = QueryShape::attemptedBy(fn (): mixed => ($this->action)()->execute($run));

    expect($shape)->not->toBeNull()
        ->and($shape?->sql)->toStartWith('update "promotion_runs" set')
        ->and($shape?->hasBinding(PromotionRunStatus::Applying->value))->toBeTrue()
        ->and($shape?->hasBinding(PromotionRunStatus::Approved->value))->toBeTrue()
        ->and($shape?->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape?->hasBinding((string) $run->getKey()))->toBeTrue();
});

it('refuses a trigger from another tenant before reading anything', function (): void {
    $run = ($this->run)();
    $foreignActor = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('foreign-actor'),
        'tenant_id' => ModelStub::ulid('other-tenant'),
    ]);

    $expected = PromotionNotExecutableException::actingUserOutsideTenant();
    $caught = null;

    $shape = QueryShape::attemptedBy(function () use ($run, $foreignActor, &$caught): void {
        try {
            ($this->call)('executeClaimed', [$run, $foreignActor]);
        } catch (PromotionNotExecutableException $exception) {
            $caught = $exception;
        }
    });

    expect($caught?->getMessage())->toBe($expected->getMessage())
        ->and($shape)->toBeNull();
});

it('refuses a trigger who lost the promotion permission', function (): void {
    $run = ($this->run)();
    $actor = AccessContext::user($this->tenant);
    AccessContext::grant('something.else');

    $expected = PromotionNotExecutableException::triggerWithoutAuthority();

    expect(fn (): mixed => ($this->call)('executeClaimed', [$run, $actor]))
        ->toThrow(PromotionNotExecutableException::class, $expected->getMessage());
});

it('refuses a run whose selection carries no artifact kind or key', function (): void {
    $run = ($this->run)(['selection' => (string) json_encode([['kind' => 'object-types']])]);

    expect(fn (): mixed => ($this->call)('selectionOf', [$run]))
        ->toThrow(InvalidArgumentException::class, 'Jeder Eintrag einer Promotionauswahl benötigt eine Artefaktart und einen fachlichen Schlüssel.');
});

it('reads a well formed selection into artifact kind and key pairs', function (): void {
    $run = ($this->run)(['selection' => (string) json_encode([['kind' => ArtifactKind::ObjectTypes->value, 'key' => 'deal']])]);

    /** @var PromotionSelection $selection */
    $selection = ($this->call)('selectionOf', [$run]);

    expect($selection->contains(ArtifactKind::ObjectTypes, 'deal'))->toBeTrue()
        ->and($selection->count())->toBe(1);
});

it('refuses a run whose conflict decisions are not arrays', function (): void {
    $run = ($this->run)(['conflict_decisions' => (string) json_encode(['not-an-array'])]);

    expect(fn (): mixed => ($this->call)('decisionsOf', [$run]))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to execute when the target drifted into a new undecided conflict since approval', function (): void {
    $diff = new BundleDiff([($this->conflictOf)(ArtifactKind::ObjectTypes, 'contact', DiffState::Conflicted)]);
    $selection = new PromotionSelection([['kind' => ArtifactKind::ObjectTypes, 'key' => 'contact']]);

    expect(fn (): mixed => ($this->call)('assertConflictsUnchanged', [$diff, $selection, []]))
        ->toThrow(
            PromotionNotExecutableException::class,
            PromotionNotExecutableException::conflictsChanged([ArtifactKind::ObjectTypes->identifierFor('contact')], [])->getMessage(),
        );
});

it('refuses to execute when an earlier decision no longer matches a conflict', function (): void {
    $diff = new BundleDiff([($this->conflictOf)(ArtifactKind::ObjectTypes, 'contact', DiffState::Modified)]);
    $selection = new PromotionSelection([['kind' => ArtifactKind::ObjectTypes, 'key' => 'contact']]);
    $decisions = [($this->decisionOf)(ArtifactKind::ObjectTypes, 'contact')];

    expect(fn (): mixed => ($this->call)('assertConflictsUnchanged', [$diff, $selection, $decisions]))
        ->toThrow(
            PromotionNotExecutableException::class,
            PromotionNotExecutableException::conflictsChanged([], [ArtifactKind::ObjectTypes->identifierFor('contact')])->getMessage(),
        );
});

it('lets a decided conflict and an unselected conflict pass', function (): void {
    $diff = new BundleDiff([
        ($this->conflictOf)(ArtifactKind::ObjectTypes, 'contact', DiffState::Conflicted),
        ($this->conflictOf)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Conflicted),
    ]);
    $selection = new PromotionSelection([['kind' => ArtifactKind::ObjectTypes, 'key' => 'contact']]);
    $decisions = [($this->decisionOf)(ArtifactKind::ObjectTypes, 'contact')];

    expect(($this->call)('assertConflictsUnchanged', [$diff, $selection, $decisions]))->toBeNull();
});

it('writes a generic german error into the report instead of a technical message', function (): void {
    $generic = 'Die Promotion ist an einem unerwarteten technischen Fehler gescheitert; die Einzelheiten stehen im Anwendungsprotokoll.';

    expect(($this->call)('reportableMessageOf', [new RuntimeException('pg_dump: connection refused')]))->toBe($generic)
        ->and(($this->call)('reportableMessageOf', [new LogicException('null pointer somewhere')]))->toBe($generic);
});

it('keeps the message of an exception that is meant for users', function (): void {
    foreach ([
        PromotionSourceUnavailableException::bundleDirectoryMissing(),
        PromotionNotExecutableException::notApproved(),
        MalformedBundleException::missingManifest('manifest.yaml'),
        new TenantUnderMaintenanceException((string) $this->tenant->getKey(), 'Wartungsmodus'),
    ] as $exception) {
        expect(($this->call)('reportableMessageOf', [$exception]))->toBe($exception->getMessage());
    }
});

it('writes the german validation message of a refused write into the report', function (): void {
    $exception = ValidationException::withMessages([
        'storage_strategy' => 'Die Speicherstrategie lässt sich nicht mehr ändern.',
        'business_key_prefix' => 'Der Code lässt sich nicht mehr ändern.',
    ]);

    expect(($this->call)('reportableMessageOf', [$exception]))
        ->toBe('Die Speicherstrategie lässt sich nicht mehr ändern. Der Code lässt sich nicht mehr ändern.');
});

it('states every execution refusal in german', function (): void {
    expect(PromotionNotExecutableException::notApproved()->getMessage())
        ->toContain('nicht')
        ->and(PromotionNotExecutableException::actingUserOutsideTenant()->getMessage())
        ->toBe('Der auslösende Benutzer gehört nicht zum Zielmandanten dieser Promotion; sie wird nicht ausgeführt.')
        ->and(PromotionNotExecutableException::triggerWithoutAuthority()->getMessage())
        ->toBe('Der auslösende Benutzer hat im Zielmandanten nicht mehr das Recht zur Promotion; sie wird nicht ausgeführt.');
});
