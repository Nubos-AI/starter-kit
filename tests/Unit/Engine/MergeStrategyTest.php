<?php

declare(strict_types=1);

use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;
use App\Exceptions\Engine\UnknownMergeStrategyException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\MergeStrategyRegistry;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('merge-strategy-object-type');

    /** @var callable(FieldType):FieldDefinition */
    $this->field = fn (FieldType $type): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('merge-strategy-field-'.$type->value),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => 'value',
        'field_type' => $type,
    ]);

    /** @var callable(array<string, mixed>, string):CustomRecord */
    $this->record = fn (array $data, string $updatedAt = '2026-01-01 10:00:00'): CustomRecord => ModelStub::make(
        CustomRecord::class,
        [
            'id' => ModelStub::ulid('merge-strategy-record-'.$updatedAt.json_encode($data)),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'data' => $data,
            'version' => 1,
            'updated_at' => $updatedAt,
        ],
    );

    /** @var callable(MergeFieldStrategy, FieldDefinition, CustomRecord, CustomRecord):MergeValueOutcome */
    $this->apply = fn (
        MergeFieldStrategy $strategy,
        FieldDefinition $field,
        CustomRecord $target,
        CustomRecord $source,
    ): MergeValueOutcome => app(MergeStrategyRegistry::class)
        ->handlerFor($strategy)
        ->resolve(new MergeValueContext($field, $target, $source));
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets the filled value beat the empty one in either direction', function (): void {
    $field = ($this->field)(FieldType::TextShort);

    $emptyTarget = ($this->apply)(
        MergeFieldStrategy::PreferNonEmpty,
        $field,
        ($this->record)(['value' => null]),
        ($this->record)(['value' => 'aus der Quelle']),
    );

    $emptySource = ($this->apply)(
        MergeFieldStrategy::PreferNonEmpty,
        $field,
        ($this->record)(['value' => 'aus dem Ziel']),
        ($this->record)(['value' => '']),
    );

    expect($emptyTarget->value)->toBe('aus der Quelle')
        ->and($emptyTarget->origin)->toBe(MergeValueOrigin::Source)
        ->and($emptySource->value)->toBe('aus dem Ziel')
        ->and($emptySource->origin)->toBe(MergeValueOrigin::Target);
});

it('keeps the target when both sides carry a value', function (): void {
    $outcome = ($this->apply)(
        MergeFieldStrategy::PreferNonEmpty,
        ($this->field)(FieldType::TextShort),
        ($this->record)(['value' => 'Ziel']),
        ($this->record)(['value' => 'Quelle']),
    );

    expect($outcome->value)->toBe('Ziel')
        ->and($outcome->origin)->toBe(MergeValueOrigin::Target)
        ->and($outcome->requiresDecision)->toBeFalse();
});

it('takes the fixed side even when that side is empty', function (): void {
    $field = ($this->field)(FieldType::TextShort);
    $target = ($this->record)(['value' => 'Ziel']);
    $source = ($this->record)(['value' => 'Quelle']);

    $emptySource = ($this->apply)(
        MergeFieldStrategy::PreferSource,
        $field,
        $target,
        ($this->record)(['value' => null]),
    );

    expect(($this->apply)(MergeFieldStrategy::PreferTarget, $field, $target, $source)->value)->toBe('Ziel')
        ->and(($this->apply)(MergeFieldStrategy::PreferSource, $field, $target, $source)->value)->toBe('Quelle')
        ->and($emptySource->value)->toBeNull()
        ->and($emptySource->origin)->toBe(MergeValueOrigin::Source);
});

it('decides by the last change for the newest and the oldest strategy', function (): void {
    $field = ($this->field)(FieldType::TextShort);
    $target = ($this->record)(['value' => 'Ziel'], '2026-01-01 10:00:00');
    $source = ($this->record)(['value' => 'Quelle'], '2026-06-01 10:00:00');

    expect(($this->apply)(MergeFieldStrategy::PreferNewest, $field, $target, $source)->value)->toBe('Quelle')
        ->and(($this->apply)(MergeFieldStrategy::PreferOldest, $field, $target, $source)->value)->toBe('Ziel');
});

it('concatenates both texts and skips the empty side', function (): void {
    $field = ($this->field)(FieldType::TextLong);

    $both = ($this->apply)(
        MergeFieldStrategy::Concatenate,
        $field,
        ($this->record)(['value' => 'Erste Notiz']),
        ($this->record)(['value' => 'Zweite Notiz']),
    );

    $one = ($this->apply)(
        MergeFieldStrategy::Concatenate,
        $field,
        ($this->record)(['value' => 'Erste Notiz']),
        ($this->record)(['value' => null]),
    );

    expect($both->value)->toBe("Erste Notiz\n\nZweite Notiz")
        ->and($both->origin)->toBe(MergeValueOrigin::Combined)
        ->and($one->value)->toBe('Erste Notiz')
        ->and($one->origin)->toBe(MergeValueOrigin::Target);
});

it('keeps every entry of a union once and in a stable order', function (): void {
    $field = ($this->field)(FieldType::MultiSelect);

    $mixed = ($this->apply)(
        MergeFieldStrategy::Union,
        $field,
        ($this->record)(['value' => ['a', 'b']]),
        ($this->record)(['value' => ['b', 'c']]),
    );

    $identical = ($this->apply)(
        MergeFieldStrategy::Union,
        $field,
        ($this->record)(['value' => ['a']]),
        ($this->record)(['value' => ['a']]),
    );

    expect($mixed->value)->toBe(['a', 'b', 'c'])
        ->and($mixed->origin)->toBe(MergeValueOrigin::Combined)
        ->and($identical->value)->toBe(['a']);
});

it('adds both numbers and treats a missing one as nothing', function (): void {
    $field = ($this->field)(FieldType::Number);

    expect(($this->apply)(MergeFieldStrategy::Sum, $field, ($this->record)(['value' => 3]), ($this->record)(['value' => 4]))->value)->toBe(7)
        ->and(($this->apply)(MergeFieldStrategy::Sum, $field, ($this->record)(['value' => 3]), ($this->record)(['value' => null]))->value)->toBe(3)
        ->and(($this->apply)(MergeFieldStrategy::Sum, $field, ($this->record)(['value' => null]), ($this->record)(['value' => null]))->value)->toBeNull();
});

it('picks the larger and the smaller number together with its origin', function (): void {
    $field = ($this->field)(FieldType::Number);
    $target = ($this->record)(['value' => 3]);
    $source = ($this->record)(['value' => 9]);

    $max = ($this->apply)(MergeFieldStrategy::Max, $field, $target, $source);
    $min = ($this->apply)(MergeFieldStrategy::Min, $field, $target, $source);

    expect($max->value)->toBe(9)
        ->and($max->origin)->toBe(MergeValueOrigin::Source)
        ->and($min->value)->toBe(3)
        ->and($min->origin)->toBe(MergeValueOrigin::Target);
});

it('picks the later and the earlier date as text', function (): void {
    $field = ($this->field)(FieldType::Date);
    $target = ($this->record)(['value' => '2026-01-31']);
    $source = ($this->record)(['value' => '2026-03-01']);

    expect(($this->apply)(MergeFieldStrategy::Max, $field, $target, $source)->value)->toBe('2026-03-01')
        ->and(($this->apply)(MergeFieldStrategy::Min, $field, $target, $source)->value)->toBe('2026-01-31');
});

it('never decides a manual field on its own', function (): void {
    $outcome = ($this->apply)(
        MergeFieldStrategy::Manual,
        ($this->field)(FieldType::TextShort),
        ($this->record)(['value' => 'Ziel']),
        ($this->record)(['value' => 'Quelle']),
    );

    expect($outcome->requiresDecision)->toBeTrue()
        ->and($outcome->origin)->toBe(MergeValueOrigin::Undecided)
        ->and($outcome->value)->toBe('Ziel');
});

it('has a handler behind every strategy of the catalogue', function (): void {
    $registry = app(MergeStrategyRegistry::class);

    foreach (MergeFieldStrategy::cases() as $strategy) {
        expect($registry->handlerFor($strategy)->strategy())->toBe($strategy);
    }
});

it('refuses a strategy no handler is registered for', function (): void {
    config()->set('engine.merge.strategies', []);

    expect(fn (): mixed => app(MergeStrategyRegistry::class)->handlerFor(MergeFieldStrategy::Sum))
        ->toThrow(UnknownMergeStrategyException::class);
});

it('hands an unset multi select to the handlers as an empty list and an unset text as null', function (): void {
    $list = new MergeValueContext(($this->field)(FieldType::MultiSelect), ($this->record)([]), ($this->record)([]));
    $text = new MergeValueContext(($this->field)(FieldType::TextShort), ($this->record)([]), ($this->record)([]));

    expect($list->targetValue())->toBe([])
        ->and($list->sourceValue())->toBe([])
        ->and($list->targetIsEmpty())->toBeTrue()
        ->and($text->targetValue())->toBeNull()
        ->and($text->sourceValue())->toBeNull();

    foreach ([MergeFieldStrategy::PreferNonEmpty, MergeFieldStrategy::PreferSource, MergeFieldStrategy::Union] as $strategy) {
        expect(app(MergeStrategyRegistry::class)->handlerFor($strategy)->resolve($list)->value)->toBe([]);
    }
});
