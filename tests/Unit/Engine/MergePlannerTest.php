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
use App\Support\Engine\MergePreflight;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('planner-object-type');

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

it('refuses a trashed record and a record that was merged away already', function (): void {
    $target = ($this->record)('target');

    $trashed = ($this->record)('trashed', ['deleted_at' => '2026-01-01 00:00:00']);
    $merged = ($this->record)('merged', ['merged_into_record_id' => ModelStub::ulid('survivor')]);

    expect(($this->reasons)(($this->check)($target, $trashed)))
        ->toBe([MergeBlockReason::Trashed->value])
        ->and(($this->reasons)(($this->check)($target, $merged)))
        ->toBe([MergeBlockReason::AlreadyMerged->value]);
});

it('stops at the cheap blockers and never looks the object type up', function (): void {
    $target = ($this->record)('target');
    $trashed = ($this->record)('trashed', ['deleted_at' => '2026-01-01 00:00:00']);

    $shape = QueryShape::attemptedBy(fn (): mixed => ($this->check)($target, $trashed));

    expect($shape)->toBeNull();
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

it('is mergeable only while no blocker is left and keeps the surviving values', function (): void {
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
        ->and($clean->resultingData())->toBe(['title' => 'Ziel']);
});
