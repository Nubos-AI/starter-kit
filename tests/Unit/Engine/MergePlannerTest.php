<?php

declare(strict_types=1);

use App\DTOs\Engine\MergeBlocker;
use App\DTOs\Engine\MergeFieldPlan;
use App\DTOs\Engine\MergePlanData;
use App\DTOs\Engine\MergeRequestData;
use App\DTOs\Engine\MergeRuleDecision;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\MergeBlockReason;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeRuleMode;
use App\Enums\Engine\MergeValueOrigin;
use App\Models\CustomRecord;
use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Support\Engine\MergePreflight;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordTreeQuery;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('planner-object-type');

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry);

    /** @var callable(string, array<string, mixed>):CustomRecord */
    $this->record = fn (string $seed, array $overrides = []): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('planner-record-'.$seed),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'data' => [],
        'version' => 1,
        'merged_into_record_id' => null,
        'deleted_at' => null,
        ...$overrides,
    ]);

    /** @var callable(list<MergeBlocker>):list<string> */
    $this->reasons = fn (array $blockers): array => array_map(
        static fn (MergeBlocker $blocker): string => $blocker->reason->value,
        $blockers,
    );

    /** @var callable(CustomRecord, CustomRecord):list<MergeBlocker> */
    $this->check = fn (CustomRecord $target, CustomRecord $source): array => app(MergePreflight::class)->check(
        $target,
        $source,
        new MergeRuleDecision(MergeRuleMode::Allow),
        new MergeRequestData((string) $target->getKey(), (string) $source->getKey()),
    );

    $this->objectType = fn (array $attributes = []): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deal',
        'is_system' => false,
        'hierarchy_relationship_type_id' => null,
        ...$attributes,
    ]);

    /** @var callable(array<string, mixed>):MergeRule */
    $this->rule = fn (array $attributes = []): MergeRule => ModelStub::make(MergeRule::class, [
        'id' => ModelStub::ulid('planner-merge-rule'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'name' => 'rule',
        'mode' => MergeRuleMode::Allow->value,
        'position' => 10,
        'is_active' => true,
        'field_strategies' => [],
        'transfer_policy' => [],
        'options' => [],
        ...$attributes,
    ]);

    /** @var callable(?ObjectType):MergePreflight */
    $this->preflightFor = function (?ObjectType $objectType = null): MergePreflight {
        $registry = Mockery::mock(ObjectTypeRegistry::class);
        $registry->shouldReceive('forRecord')->andReturn($objectType ?? ($this->objectType)());

        return new MergePreflight($registry, Mockery::mock(RecordTreeQuery::class));
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('names the pair itself as the only blocker and asks the database nothing more', function (): void {
    $record = ($this->record)('alone');

    $blockers = [];

    $shape = QueryShape::attemptedBy(function () use ($record, &$blockers): void {
        $blockers = ($this->check)($record, $record);
    });

    expect(($this->reasons)($blockers))->toBe([MergeBlockReason::SameRecord->value])
        ->and($shape)->toBeNull();
});

it('refuses a pair that does not share object type and tenant', function (): void {
    $target = ($this->record)('target');

    $foreignType = ($this->record)('foreign-type', ['object_type_id' => ModelStub::ulid('other-object-type')]);
    $foreignTenant = ($this->record)('foreign-tenant', ['tenant_id' => ModelStub::ulid('other-tenant')]);

    expect(($this->reasons)(($this->check)($target, $foreignType)))
        ->toBe([MergeBlockReason::DifferentObjectType->value])
        ->and(($this->reasons)(($this->check)($target, $foreignTenant)))
        ->toBe([MergeBlockReason::DifferentTenant->value]);
});

it('refuses a trashed record and a record that was merged away already without looking the object type up', function (string $seed, array $overrides, MergeBlockReason $reason): void {
    $target = ($this->record)('target');
    $source = ($this->record)($seed, $overrides);

    $blockers = [];

    $shape = QueryShape::attemptedBy(function () use ($target, $source, &$blockers): void {
        $blockers = ($this->check)($target, $source);
    });

    expect(($this->reasons)($blockers))->toBe([$reason->value])
        ->and($shape)->toBeNull();
})->with([
    'trashed record' => ['trashed', ['deleted_at' => '2026-01-01 00:00:00'], MergeBlockReason::Trashed],
    'record merged away already' => ['merged', ['merged_into_record_id' => ModelStub::ulid('survivor')], MergeBlockReason::AlreadyMerged],
]);

it('refuses a pair a deny rule blocks and carries its reason', function (): void {
    $target = ($this->record)('target');
    $source = ($this->record)('source');

    $blockers = ($this->preflightFor)()->check(
        $target,
        $source,
        new MergeRuleDecision(MergeRuleMode::Deny, ($this->rule)(), 'Gesperrt.'),
        new MergeRequestData((string) $target->getKey(), (string) $source->getKey()),
    );

    expect(($this->reasons)($blockers))->toBe([MergeBlockReason::RuleForbids->value])
        ->and($blockers[0]->detail)->toBe('Gesperrt.');
});

it('refuses a stale version on either side', function (string $side): void {
    $target = ($this->record)('target', ['version' => 3]);
    $source = ($this->record)('source', ['version' => 3]);

    $blockers = ($this->preflightFor)()->check(
        $target,
        $source,
        new MergeRuleDecision(MergeRuleMode::Allow),
        new MergeRequestData(
            (string) $target->getKey(),
            (string) $source->getKey(),
            targetVersion: $side === 'target' ? 6 : null,
            sourceVersion: $side === 'source' ? 6 : null,
        ),
    );

    expect(($this->reasons)($blockers))->toBe([MergeBlockReason::StaleVersion->value]);
})->with(['target', 'source']);

it('refuses a merge on a system object type', function (): void {
    $target = ($this->record)('target');
    $source = ($this->record)('source');

    $blockers = ($this->preflightFor)(($this->objectType)(['is_system' => true]))->check(
        $target,
        $source,
        new MergeRuleDecision(MergeRuleMode::Allow),
        new MergeRequestData((string) $target->getKey(), (string) $source->getKey()),
    );

    expect(($this->reasons)($blockers))->toBe([MergeBlockReason::SystemObjectType->value]);
});

it('refuses a merge without the reason the rule demands and lets it pass with one', function (): void {
    $decision = new MergeRuleDecision(MergeRuleMode::Allow, ($this->rule)(['options' => ['requires_reason' => true]]));
    $preflight = ($this->preflightFor)();
    $target = ($this->record)('target');
    $source = ($this->record)('source');

    $withoutReason = $preflight->check(
        $target,
        $source,
        $decision,
        new MergeRequestData((string) $target->getKey(), (string) $source->getKey()),
    );

    $withReason = $preflight->check(
        $target,
        $source,
        $decision,
        new MergeRequestData((string) $target->getKey(), (string) $source->getKey(), reason: 'Dublette aus dem Import.'),
    );

    expect(($this->reasons)($withoutReason))->toBe([MergeBlockReason::ReasonRequired->value])
        ->and(($this->reasons)($withReason))->toBe([]);
});

it('lets an eligible pair through without a single blocker', function (): void {
    $target = ($this->record)('target', ['data' => ['title' => 'Ziel']]);
    $source = ($this->record)('source', ['data' => ['title' => 'Quelle']]);

    $blockers = ($this->preflightFor)()->check(
        $target,
        $source,
        new MergeRuleDecision(MergeRuleMode::Allow),
        new MergeRequestData((string) $target->getKey(), (string) $source->getKey(), targetVersion: 1, sourceVersion: 1),
    );

    expect($blockers)->toBe([]);
});

it('accepts only a target or a source override and ignores anything else', function (): void {
    $request = new MergeRequestData('target', 'source', overrides: [
        'title' => MergeValueOrigin::Source->value,
        'volume' => MergeValueOrigin::Target->value,
        'note' => MergeValueOrigin::Combined->value,
        'stage' => 'nonsense',
    ]);

    expect($request->overrideFor('title'))->toBe(MergeValueOrigin::Source)
        ->and($request->overrideFor('volume'))->toBe(MergeValueOrigin::Target)
        ->and($request->overrideFor('note'))->toBeNull()
        ->and($request->overrideFor('stage'))->toBeNull()
        ->and($request->overrideFor('absent'))->toBeNull();
});

it('treats a blank reason as no reason at all', function (): void {
    expect((new MergeRequestData('target', 'source', reason: '   '))->hasReason())->toBeFalse()
        ->and((new MergeRequestData('target', 'source', reason: 'Doppelte Firma.'))->hasReason())->toBeTrue()
        ->and((new MergeRequestData('target', 'source'))->hasReason())->toBeFalse();
});

it('is mergeable only while no blocker is left and sends no field whose value survives unchanged', function (): void {
    $plan = fn (array $blockers): MergePlanData => new MergePlanData(
        'target',
        'source',
        3,
        2,
        MergeRuleMode::Allow,
        null,
        null,
        null,
        false,
        [
            new MergeFieldPlan(
                'title',
                'Titel',
                FieldType::TextShort,
                MergeFieldStrategy::PreferNonEmpty,
                'Ziel',
                'Quelle',
                'Ziel',
                MergeValueOrigin::Target,
                true,
                false,
                false,
            ),
            new MergeFieldPlan(
                'note',
                'Notiz',
                FieldType::TextLong,
                MergeFieldStrategy::PreferNonEmpty,
                null,
                null,
                null,
                MergeValueOrigin::Target,
                false,
                false,
                false,
            ),
        ],
        [],
        $blockers,
    );

    $clean = $plan([]);
    $blocked = $plan([new MergeBlocker(MergeBlockReason::DecisionMissing, 'title')]);

    expect($clean->isMergeable())->toBeTrue()
        ->and($blocked->isMergeable())->toBeFalse()
        ->and($clean->resultingData())->toBe([]);
});

it('writes only the resolved values that differ from the target', function (): void {
    $plan = new MergePlanData(
        'target',
        'source',
        1,
        1,
        MergeRuleMode::Allow,
        null,
        null,
        null,
        false,
        [
            new MergeFieldPlan('title', 'Titel', FieldType::TextShort, MergeFieldStrategy::PreferNonEmpty, 'Ziel', 'Quelle', 'Ziel', MergeValueOrigin::Target, true, false, false),
            new MergeFieldPlan('volume', 'Menge', FieldType::Number, MergeFieldStrategy::PreferNonEmpty, null, 42, 42, MergeValueOrigin::Source, false, false, false),
        ],
        [],
        [],
    );

    expect($plan->resultingData())->toBe(['volume' => 42])
        ->and($plan->isMergeable())->toBeTrue();
});
