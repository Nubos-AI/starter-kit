<?php

declare(strict_types=1);

use App\DTOs\Engine\MergeRuleDecision;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeRuleMode;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeTransferPolicy;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\MergeRule;
use App\Support\Engine\MergeRuleResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('merge-rule-object-type');

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, array $overrides = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('merge-rule-field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            ...$overrides,
        ],
    );

    /** @var callable(array<string, mixed>):MergeRule */
    $this->rule = fn (array $attributes): MergeRule => ModelStub::make(MergeRule::class, [
        'id' => ModelStub::ulid('merge-rule-'.json_encode($attributes)),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'name' => 'rule',
        'mode' => MergeRuleMode::Allow,
        'position' => 10,
        'is_active' => true,
        'field_strategies' => [],
        'transfer_policy' => [],
        'options' => [],
        ...$attributes,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('falls back to the system default when no rule was chosen at all', function (): void {
    $decision = new MergeRuleDecision(MergeRuleMode::Allow);

    expect($decision->forbids())->toBeFalse()
        ->and($decision->rule)->toBeNull()
        ->and($decision->denyReason)->toBeNull()
        ->and($decision->strategyFor(($this->field)('stage', FieldType::TextShort)))->toBe(MergeFieldStrategy::PreferNonEmpty)
        ->and($decision->policyFor(MergeTransferCategory::Links))->toBe(MergeTransferPolicy::Move)
        ->and($decision->policyFor(MergeTransferCategory::Audit))->toBe(MergeTransferPolicy::Keep)
        ->and($decision->policyFor(MergeTransferCategory::AgingStates))->toBe(MergeTransferPolicy::Discard);
});

it('prefers the rule strategy over the one stored on the field definition', function (): void {
    $decision = new MergeRuleDecision(MergeRuleMode::Allow, ($this->rule)([
        'field_strategies' => ['volume' => MergeFieldStrategy::Sum->value],
    ]));

    $volume = ($this->field)('volume', FieldType::Number, ['merge_strategy' => MergeFieldStrategy::Max]);

    expect($decision->strategyFor($volume))->toBe(MergeFieldStrategy::Sum);
});

it('falls back from the rule to the field definition and then to the default', function (): void {
    $decision = new MergeRuleDecision(MergeRuleMode::Allow, ($this->rule)([
        'field_strategies' => ['stage' => MergeFieldStrategy::PreferSource->value],
    ]));

    $stage = ($this->field)('stage', FieldType::TextShort);
    $volume = ($this->field)('volume', FieldType::Number, ['merge_strategy' => MergeFieldStrategy::Max]);
    $untouched = ($this->field)('note', FieldType::TextLong);

    expect($decision->strategyFor($stage))->toBe(MergeFieldStrategy::PreferSource)
        ->and($decision->strategyFor($volume))->toBe(MergeFieldStrategy::Max)
        ->and($decision->strategyFor($untouched))->toBe(MergeFieldStrategy::PreferNonEmpty);
});

it('ignores a configured strategy the field type cannot carry', function (): void {
    $decision = new MergeRuleDecision(MergeRuleMode::Allow, ($this->rule)([
        'field_strategies' => ['stage' => MergeFieldStrategy::Sum->value],
    ]));

    expect($decision->strategyFor(($this->field)('stage', FieldType::TextShort)))
        ->toBe(MergeFieldStrategy::PreferNonEmpty);
});

it('ignores a transfer policy the category forbids and keeps its own default', function (): void {
    $decision = new MergeRuleDecision(MergeRuleMode::Allow, ($this->rule)([
        'transfer_policy' => [
            MergeTransferCategory::Links->value => MergeTransferPolicy::Keep->value,
            MergeTransferCategory::Audit->value => MergeTransferPolicy::Move->value,
        ],
    ]));

    expect($decision->policyFor(MergeTransferCategory::Links))->toBe(MergeTransferPolicy::Keep)
        ->and($decision->policyFor(MergeTransferCategory::Audit))->toBe(MergeTransferPolicy::Keep)
        ->and($decision->policyFor(MergeTransferCategory::Notes))->toBe(MergeTransferPolicy::Move);
});

it('reads an option as off unless the chosen rule switches it on', function (): void {
    $without = new MergeRuleDecision(MergeRuleMode::Allow);
    $with = new MergeRuleDecision(MergeRuleMode::Allow, ($this->rule)([
        'options' => ['requires_reason' => true],
    ]));

    expect($without->requiresReason())->toBeFalse()
        ->and($without->requiresDedupMatch())->toBeFalse()
        ->and($without->blocksOnRunningAutomations())->toBeFalse()
        ->and($with->requiresReason())->toBeTrue()
        ->and($with->requiresDedupMatch())->toBeFalse();
});

it('reports a deny decision together with the reason shown to the user', function (): void {
    $decision = new MergeRuleDecision(
        MergeRuleMode::Deny,
        ($this->rule)(['mode' => MergeRuleMode::Deny]),
        'Abgeschlossene Vorgaenge lassen sich nicht mehr zusammenfuehren.',
    );

    expect($decision->forbids())->toBeTrue()
        ->and($decision->denyReason)->toBe('Abgeschlossene Vorgaenge lassen sich nicht mehr zusammenfuehren.');
});

it('asks only for the active rules of the object type and in position order', function (): void {
    $record = fn (string $seed): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'data' => [],
    ]);

    $shape = QueryShape::attemptedBy(fn (): mixed => app(MergeRuleResolver::class)
        ->resolve($record('merge-target'), $record('merge-source')));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('merge_rules'))->toBeTrue()
        ->and($shape->sql)->toContain('"object_type_id" = ?')
        ->and($shape->sql)->toContain('"is_active" = ?')
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue()
        ->and($shape->isScopedToTenant('merge_rules', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('merge_rules'))->toBeTrue()
        ->and($shape->sql)->toContain('order by "position" asc, "created_at" asc');
});
