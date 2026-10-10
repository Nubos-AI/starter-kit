<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\RollupScope;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\RollupFieldConfigValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->parentTypeId = ModelStub::ulid('parent-type');
    $this->childTypeId = ModelStub::ulid('child-type');
    $this->relationshipId = ModelStub::ulid('relationship');

    $this->hierarchicalChild = ModelStub::make(ObjectType::class, [
        'id' => $this->childTypeId,
        'name' => 'Positions',
        'hierarchy_relationship_type_id' => ModelStub::ulid('carrier'),
    ]);

    $this->flatChild = ModelStub::make(ObjectType::class, [
        'id' => $this->childTypeId,
        'name' => 'Partners',
        'hierarchy_relationship_type_id' => null,
    ]);

    $this->ownType = ModelStub::make(ObjectType::class, [
        'id' => $this->parentTypeId,
        'name' => 'Orders',
        'hierarchy_relationship_type_id' => null,
    ]);

    $this->filterTreeValidator = Mockery::mock(FilterTreeValidator::class);
    $this->capabilities = Mockery::mock(ObjectTypeCapabilityGuard::class);
    $this->lookup = Mockery::mock(ObjectTypeFieldLookup::class);

    $this->capabilities->shouldReceive('supports')->andReturn(true)->byDefault();
    $this->lookup->shouldReceive('filterableFields')->andReturn(new Collection)->byDefault();
    $this->filterTreeValidator->shouldReceive('validate')->andReturnNull()->byDefault();

    /** @var callable(string, ObjectType):void */
    $this->targetIs = function (string $relationshipTypeId, ObjectType $objectType): void {
        $this->lookup->shouldReceive('relationshipTarget')
            ->with($relationshipTypeId)
            ->andReturn((string) $objectType->getKey());

        $this->lookup->shouldReceive('objectTypeOrFail')
            ->with((string) $objectType->getKey())
            ->andReturn($objectType);
    };

    /** @var callable(array<string, mixed>|null):FieldDefinition */
    $this->rollup = fn (?array $config): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('rollup'),
        'object_type_id' => $this->parentTypeId,
        'key' => 'total',
        'field_type' => FieldType::Rollup,
        'config' => $config,
    ]);

    /** @var callable(array<string, mixed>):array<string, mixed> */
    $this->config = fn (array $overrides = []): array => [
        'aggregate' => 'sum',
        'relationship_type_id' => $this->relationshipId,
        'source_field_key' => 'amount',
        ...$overrides,
    ];

    /** @var callable():RollupFieldConfigValidator */
    $this->validator = fn (): RollupFieldConfigValidator => new RollupFieldConfigValidator(
        $this->filterTreeValidator,
        app(FilterFieldKeyCollector::class),
        $this->capabilities,
        $this->lookup,
    );

    /** @var callable(FieldDefinition):string */
    $this->configError = function (FieldDefinition $field): string {
        try {
            ($this->validator)()->validate($field);
        } catch (ValidationException $exception) {
            return implode(' ', $exception->errors()['config'] ?? []);
        }

        return '';
    };
});

it('aggregates the direct children of the relationship target when no scope is configured', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)()));

    expect($configuration->scope)->toBe(RollupScope::DirectChildren)
        ->and($configuration->targetObjectTypeId)->toBe($this->childTypeId)
        ->and($configuration->relationshipTypeId)->toBe($this->relationshipId)
        ->and($configuration->sourceFieldKey)->toBe('amount')
        ->and($configuration->filterFieldKeys)->toBe([]);
});

it('refuses a scope value that is neither the direct children nor the subtree', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    expect(($this->configError)(($this->rollup)(($this->config)(['scope' => 'entire_universe']))))->not->toBe('');
});

it('refuses a scope that is not even written as a string', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    expect(($this->configError)(($this->rollup)(($this->config)(['scope' => 7]))))->not->toBe('');
});

it('accepts the subtree scope when the target object type carries a hierarchy', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)(['scope' => 'subtree'])));

    expect($configuration->scope)->toBe(RollupScope::Subtree);
});

it('refuses the subtree scope when the target object type has no hierarchy', function (): void {
    ($this->targetIs)($this->relationshipId, $this->flatChild);

    expect(($this->configError)(($this->rollup)(($this->config)(['scope' => 'subtree']))))->not->toBe('');
});

it('refuses a roll-up over an object type that cannot be aggregated', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $this->capabilities = Mockery::mock(ObjectTypeCapabilityGuard::class);
    $this->capabilities->shouldReceive('supports')
        ->with(Mockery::type(ObjectType::class), ObjectTypeCapability::Rollups)
        ->andReturn(false);

    expect(($this->configError)(($this->rollup)(($this->config)())))->toContain('Positions');
});

it('aggregates the fields own object type when the configured relationship no longer resolves', function (): void {
    $this->lookup->shouldReceive('relationshipTarget')->with($this->relationshipId)->andReturnNull();
    $this->lookup->shouldReceive('objectTypeOrFail')->with($this->parentTypeId)->andReturn($this->ownType);

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)()));

    expect($configuration->targetObjectTypeId)->toBe($this->parentTypeId);
});

it('aggregates the fields own object type when no relationship is configured at all', function (): void {
    $this->lookup->shouldReceive('objectTypeOrFail')->with($this->parentTypeId)->andReturn($this->ownType);
    $this->lookup->shouldNotReceive('relationshipTarget');

    $configuration = ($this->validator)()->validate(($this->rollup)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => '',
    ]));

    expect($configuration->targetObjectTypeId)->toBe($this->parentTypeId)
        ->and($configuration->relationshipTypeId)->toBeNull();
});

it('collects every field a filter names, including the ones nested in a condition group', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)([
        'filter' => ['combinator' => 'and', 'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
            ['combinator' => 'or', 'conditions' => [
                ['field' => 'region', 'operator' => 'contains', 'value' => 'north'],
                ['field' => 'status', 'operator' => 'equals', 'value' => 'idle'],
            ]],
        ]],
    ])));

    expect($configuration->filterFieldKeys)->toBe(['status', 'region'])
        ->and($configuration->dependsOnFieldKeys())->toBe(['amount', 'status', 'region']);
});

it('validates the filter against the filterable fields of the target object type', function (): void {
    $filterableFields = new Collection([
        ModelStub::make(FieldDefinition::class, ['id' => ModelStub::ulid('status'), 'key' => 'status']),
    ]);

    $this->lookup = Mockery::mock(ObjectTypeFieldLookup::class);
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);
    $this->lookup->shouldReceive('filterableFields')->with($this->childTypeId)->andReturn($filterableFields);

    $seen = new stdClass;
    $seen->fields = null;

    $this->filterTreeValidator = Mockery::mock(FilterTreeValidator::class);
    $this->filterTreeValidator->shouldReceive('validate')
        ->andReturnUsing(static function (array $tree, Collection $allowedFields) use ($seen): void {
            $seen->fields = $allowedFields;
        });

    ($this->validator)()->validate(($this->rollup)(($this->config)([
        'filter' => ['combinator' => 'and', 'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ]],
    ])));

    expect($seen->fields)->toBe($filterableFields);
});

it('reports a rejected filter tree on the config key instead of letting it escape', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $this->filterTreeValidator = Mockery::mock(FilterTreeValidator::class);
    $this->filterTreeValidator->shouldReceive('validate')
        ->andThrow(new InvalidFilterTreeException('The filter is nested too deeply.'));

    expect(($this->configError)(($this->rollup)(($this->config)([
        'filter' => ['combinator' => 'and', 'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ]],
    ]))))->toBe('The filter is nested too deeply.');
});

it('reports a filter the current user may not read on the config key', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $this->filterTreeValidator = Mockery::mock(FilterTreeValidator::class);
    $this->filterTreeValidator->shouldReceive('validate')
        ->andThrow(new AuthorizationException('You may neither filter nor sort by one of the chosen fields.'));

    expect(($this->configError)(($this->rollup)(($this->config)([
        'filter' => ['combinator' => 'and', 'conditions' => [
            ['field' => 'secret_note', 'operator' => 'equals', 'value' => 'x'],
        ]],
    ]))))->toBe('You may neither filter nor sort by one of the chosen fields.');
});

it('never reads the fields of the target object type when the roll-up carries no filter', function (): void {
    $this->lookup = Mockery::mock(ObjectTypeFieldLookup::class);
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);
    $this->lookup->shouldNotReceive('filterableFields');

    $this->filterTreeValidator = Mockery::mock(FilterTreeValidator::class);
    $this->filterTreeValidator->shouldNotReceive('validate');

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)(['filter' => []])));

    expect($configuration->filterFieldKeys)->toBe([]);
});
