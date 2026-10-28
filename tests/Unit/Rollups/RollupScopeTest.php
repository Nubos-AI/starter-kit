<?php

declare(strict_types=1);

use App\DTOs\Engine\RollupConfiguration;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\RollupScope;
use App\Models\FieldDefinition;
use Tests\Support\ModelStub;

beforeEach(function (): void {
    /** @var callable(array<string, mixed>|null):FieldDefinition */
    $this->rollup = static fn (?array $config): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('rollup-scope'),
        'key' => 'total',
        'field_type' => FieldType::Rollup,
        'config' => $config,
    ]);
});

it('treats a roll-up without a stored scope as one over the direct children', function (): void {
    $field = ($this->rollup)(['aggregate' => 'sum', 'source_field_key' => 'amount']);

    expect($field->rollupScope())->toBe(RollupScope::DirectChildren);
});

it('falls back to the direct children when the stored scope is not a known value', function (): void {
    $field = ($this->rollup)(['aggregate' => 'sum', 'scope' => 'entire_universe']);

    expect($field->rollupScope())->toBe(RollupScope::DirectChildren);
});

it('falls back to the direct children when the stored scope is not a string at all', function (): void {
    $field = ($this->rollup)(['aggregate' => 'sum', 'scope' => 7]);

    expect($field->rollupScope())->toBe(RollupScope::DirectChildren);
});

it('reads the subtree scope from the stored configuration', function (): void {
    $field = ($this->rollup)(['aggregate' => 'sum', 'scope' => 'subtree']);

    expect($field->rollupScope())->toBe(RollupScope::Subtree);
});

it('keeps the wire values the stored configurations are written against', function (): void {
    expect(array_map(
        static fn (RollupScope $scope): string => $scope->value,
        RollupScope::cases(),
    ))->toBe(['direct_children', 'subtree']);
});

it('depends on the source field and on every filter field without repeating one', function (): void {
    $configuration = new RollupConfiguration(
        RollupScope::Subtree,
        'relationship',
        'object-type',
        'amount',
        ['status', 'amount', 'region'],
    );

    expect($configuration->dependsOnFieldKeys())->toBe(['amount', 'status', 'region']);
});

it('depends on the filter fields alone when the roll-up counts instead of summing a field', function (): void {
    $configuration = new RollupConfiguration(
        RollupScope::DirectChildren,
        null,
        'object-type',
        null,
        ['status'],
    );

    expect($configuration->dependsOnFieldKeys())->toBe(['status']);
});
