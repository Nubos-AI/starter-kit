<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\RollupScope;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\RollupFieldConfigValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
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

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->sourceField = fn (string $key, FieldType $type, array $attributes = []): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("source-{$key}"),
        'object_type_id' => $this->childTypeId,
        'key' => $key,
        'field_type' => $type,
        'is_encrypted' => false,
        ...$attributes,
    ]);

    $this->targetFields = new Collection([($this->sourceField)('amount', FieldType::Number)]);

    /** @var callable():void */
    $this->stubTargetFields = function (): void {
        $this->lookup->shouldReceive('fields')->andReturnUsing(fn (): Collection => $this->targetFields)->byDefault();
    };

    ($this->stubTargetFields)();

    $tenant = AccessContext::tenant();
    AccessContext::actAs(AccessContext::user($tenant));

    $this->forbiddenReadKeys = [];
    $this->fieldVisibility = Mockery::mock(FieldVisibilityResolver::class);
    $this->fieldVisibility->shouldReceive('forbiddenReadFieldKeys')
        ->with(Mockery::type(User::class), Mockery::type('string'))
        ->andReturnUsing(fn (): array => $this->forbiddenReadKeys)
        ->byDefault();
    app()->instance(FieldVisibilityResolver::class, $this->fieldVisibility);
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

it('refuses a scope value that is neither the direct children nor the subtree', function (mixed $scope): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    expect(($this->configError)(($this->rollup)(($this->config)(['scope' => $scope]))))
        ->toBe('Ausgewertet werden entweder die unmittelbar untergeordneten Datensätze oder der gesamte Unterbaum.');
})->with([
    'unknown scope value' => ['entire_universe'],
    'scope not written as a string' => [7],
]);

it('accepts the subtree scope when the target object type carries a hierarchy', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)(['scope' => 'subtree'])));

    expect($configuration->scope)->toBe(RollupScope::Subtree);
});

it('refuses the subtree scope when the target object type has no hierarchy', function (): void {
    ($this->targetIs)($this->relationshipId, $this->flatChild);

    expect(($this->configError)(($this->rollup)(($this->config)(['scope' => 'subtree']))))
        ->toBe('Der gesamte Unterbaum lässt sich erst auswerten, wenn der ausgewertete Objekttyp eine aktive Einordnung hat.');
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
    ($this->stubTargetFields)();
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
    ($this->stubTargetFields)();
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);
    $this->lookup->shouldNotReceive('filterableFields');

    $this->filterTreeValidator = Mockery::mock(FilterTreeValidator::class);
    $this->filterTreeValidator->shouldNotReceive('validate');

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)(['filter' => []])));

    expect($configuration->filterFieldKeys)->toBe([]);
});

it('refuses an aggregate the roll-up engine does not know', function (mixed $aggregate): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    expect(($this->configError)(($this->rollup)(($this->config)(['aggregate' => $aggregate]))))
        ->toBe('Wählen Sie als Auswertung Summe, Anzahl, Durchschnitt, Minimum oder Maximum.');
})->with([
    'unknown aggregate' => ['median'],
    'aggregate not written as a string' => [3],
]);

it('sums when no aggregate is configured', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $config = ($this->config)();
    unset($config['aggregate']);

    expect(($this->validator)()->validate(($this->rollup)($config))->sourceFieldKey)->toBe('amount');
});

it('requires a source field for every aggregate that reads a value', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    expect(($this->configError)(($this->rollup)(($this->config)(['source_field_key' => '']))))
        ->toBe('Wählen Sie das Feld, dessen Werte ausgewertet werden.');
});

it('counts related records without a source field', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)([
        'aggregate' => 'count',
        'source_field_key' => '',
    ])));

    expect($configuration->sourceFieldKey)->toBeNull();
});

it('refuses a source field the target object type does not have', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    expect(($this->configError)(($this->rollup)(($this->config)(['source_field_key' => 'missing']))))
        ->toBe('Das Feld „missing“ gibt es beim ausgewerteten Objekttyp „Positions“ nicht.');
});

it('refuses a source field that does not hold a number', function (FieldType $type, array $attributes): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);
    $this->targetFields = new Collection([($this->sourceField)('amount', $type, $attributes)]);

    expect(($this->configError)(($this->rollup)(($this->config)())))
        ->toBe('Das Feld „amount“ enthält keine Zahl und lässt sich nicht so auswerten.');
})->with([
    'short text' => [FieldType::TextShort, []],
    'date' => [FieldType::Date, []],
    'formula with a text result' => [FieldType::Computed, ['config' => ['result_type' => 'text']]],
]);

it('accepts every source field that holds a number', function (FieldType $type, array $attributes): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);
    $this->targetFields = new Collection([($this->sourceField)('amount', $type, $attributes)]);

    expect(($this->validator)()->validate(($this->rollup)(($this->config)()))->sourceFieldKey)->toBe('amount');
})->with([
    'integer' => [FieldType::Number, []],
    'decimal' => [FieldType::Decimal, []],
    'money' => [FieldType::Money, []],
    'nested roll-up' => [FieldType::Rollup, []],
    'formula with a number result' => [FieldType::Computed, ['config' => ['result_type' => 'number']]],
]);

it('refuses an encrypted source field', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);
    $this->targetFields = new Collection([($this->sourceField)('amount', FieldType::Number, ['is_encrypted' => true])]);

    expect(($this->configError)(($this->rollup)(($this->config)())))
        ->toBe('Das Feld „amount“ ist verschlüsselt und lässt sich nicht auswerten.');
});

it('refuses a source field the creator may not read', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);
    $this->forbiddenReadKeys = ['amount'];

    expect(($this->configError)(($this->rollup)(($this->config)())))
        ->toBe('Sie dürfen das Feld „amount“ nicht sehen und deshalb nicht auswerten.');
});

it('asks the field rights of the target object type, not of the roll-up field', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);

    $this->fieldVisibility->shouldReceive('forbiddenReadFieldKeys')
        ->once()
        ->with(Mockery::type(User::class), $this->childTypeId)
        ->andReturn([]);

    ($this->validator)()->validate(($this->rollup)(($this->config)()));
});

it('skips the read check of the source field when the caller provisions without a user', function (): void {
    ($this->targetIs)($this->relationshipId, $this->hierarchicalChild);
    $this->forbiddenReadKeys = ['amount'];
    $this->fieldVisibility->shouldNotReceive('forbiddenReadFieldKeys');

    $configuration = ($this->validator)()->validate(($this->rollup)(($this->config)()), authorizeFilter: false);

    expect($configuration->sourceFieldKey)->toBe('amount');
});
