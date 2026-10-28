<?php

declare(strict_types=1);

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\SystemFilterFields;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportFieldSource;
use App\Support\Reports\ReportLinkedFieldResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('deals'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'name' => 'Deals',
    ]);

    $this->linkedObjectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'name' => 'Companies',
    ]);

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, array $overrides = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectType->getKey(),
            'key' => $key,
            'field_type' => $type->value,
            'is_required' => false,
            'is_unique' => false,
            'is_searchable' => false,
            'is_translatable' => false,
            'is_encrypted' => false,
            'is_sortable' => false,
            'is_filterable' => true,
            'is_default_column' => false,
            'i18n_labels' => ['en' => ucfirst($key)],
            ...$overrides,
        ],
    );

    $this->grossAmount = ($this->field)('gross_amount', FieldType::Money);
    $this->orderedQuantity = ($this->field)('ordered_quantity', FieldType::Number);
    $this->salesRegion = ($this->field)('sales_region', FieldType::TextShort);
    $this->closedOn = ($this->field)('closed_on', FieldType::Date);
    $this->payrollTotal = ($this->field)('payroll_total', FieldType::Money, ['is_encrypted' => true]);
    $this->internalMemo = ($this->field)('internal_memo', FieldType::TextShort, ['is_filterable' => false]);

    $this->persisted = [
        $this->grossAmount,
        $this->orderedQuantity,
        $this->salesRegion,
        $this->closedOn,
        $this->payrollTotal,
        $this->internalMemo,
    ];

    $this->linkedResolver = Mockery::mock(ReportLinkedFieldResolver::class)->makePartial();

    $this->objectTypeRegistry = Mockery::mock(ObjectTypeRegistry::class);
    $this->objectTypeRegistry->shouldReceive('find')->andReturnUsing(
        fn (string $id): ?ObjectType => $id === (string) $this->linkedObjectType->getKey() ? $this->linkedObjectType : null,
    );

    /** @var callable(list<FieldDefinition>):ReportFieldSource */
    $this->fieldSourceOf = static function (array $fields): ReportFieldSource {
        $source = Mockery::mock(ReportFieldSource::class);
        $source->shouldReceive('persistedFields')->andReturn(new EloquentCollection($fields));

        return $source;
    };

    /** @var callable(list<string>, ?list<FieldDefinition>):ReportDefinitionValidator */
    $this->validatorWith = function (array $forbiddenRead = [], ?array $fields = null): ReportDefinitionValidator {
        $fields ??= $this->persisted;

        $this->objectType->setRelation('fieldDefinitions', new EloquentCollection($fields));

        return new ReportDefinitionValidator(
            app(FilterTreeValidator::class),
            app(FilterFieldKeyCollector::class),
            new FakeFieldVisibilityResolver($forbiddenRead),
            app(SystemFilterFields::class),
            $this->linkedResolver,
            $this->objectTypeRegistry,
            ($this->fieldSourceOf)($fields),
        );
    };

    /** @var callable():User */
    $this->viewer = fn (): User => AccessContext::actAs(AccessContext::user($this->tenant));

    /** @var callable(array<string, mixed>):array<string, mixed> */
    $this->definition = static fn (array $overrides = []): array => [
        'filter_definition' => [],
        'aggregation_type' => AggregationType::Count->value,
        'aggregation_field_key' => null,
        'group_by_field_key' => null,
        'group_by_bucket' => null,
        'series_field_key' => null,
        ...$overrides,
    ];

    /** @var callable(string, string, mixed):array<string, mixed> */
    $this->condition = static fn (string $key, string $operator = 'equals', mixed $value = 'x'): array => [
        'combinator' => 'and',
        'conditions' => [['field' => $key, 'operator' => $operator, 'value' => $value]],
    ];

    /** @var callable(array<string, mixed>, list<string>, ?list<FieldDefinition>):ReportNotExecutableReason */
    $this->reasonFor = function (array $definition, array $forbiddenRead = [], ?array $fields = null): ReportNotExecutableReason {
        try {
            ($this->validatorWith)($forbiddenRead, $fields)->validate($definition, $this->objectType, ($this->viewer)());
        } catch (ReportNotExecutableException $exception) {
            return $exception->reason;
        }

        throw new RuntimeException('The definition was accepted although a refusal was expected.');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('resolves a complete definition into data carrying every referenced field definition', function (): void {
    AccessContext::grant('deals.view');

    $data = ($this->validatorWith)()->validate(
        ($this->definition)([
            'filter_definition' => ($this->condition)('sales_region', 'contains', 'north'),
            'aggregation_type' => AggregationType::Sum->value,
            'aggregation_field_key' => 'gross_amount',
            'group_by_field_key' => 'closed_on',
            'group_by_bucket' => GroupingBucket::Month->value,
            'series_field_key' => 'sales_region',
        ]),
        $this->objectType,
        ($this->viewer)(),
    );

    expect($data)->toBeInstanceOf(ReportDefinitionData::class)
        ->and($data->objectTypeId)->toBe((string) $this->objectType->getKey())
        ->and($data->aggregation)->toBe(AggregationType::Sum)
        ->and($data->aggregationField?->key)->toBe('gross_amount')
        ->and($data->groupByField?->key)->toBe('closed_on')
        ->and($data->groupByBucket)->toBe(GroupingBucket::Month)
        ->and($data->seriesField?->key)->toBe('sales_region')
        ->and(array_keys($data->fields))->toEqualCanonicalizing(['gross_amount', 'closed_on', 'sales_region']);
});

it('refuses a definition whose aggregation field the viewer may not read', function (): void {
    expect(($this->reasonFor)(
        ($this->definition)([
            'aggregation_type' => AggregationType::Sum->value,
            'aggregation_field_key' => 'gross_amount',
        ]),
        ['gross_amount'],
    ))->toBe(ReportNotExecutableReason::FieldNotReadable);
});

it('refuses a definition whose grouping field the viewer may not read', function (): void {
    expect(($this->reasonFor)(
        ($this->definition)(['group_by_field_key' => 'sales_region']),
        ['sales_region'],
    ))->toBe(ReportNotExecutableReason::FieldNotReadable);
});

it('refuses a definition whose filter field the viewer may not read', function (): void {
    expect(($this->reasonFor)(
        ($this->definition)(['filter_definition' => ($this->condition)('sales_region')]),
        ['sales_region'],
    ))->toBe(ReportNotExecutableReason::FieldNotReadable);
});

it('accepts the same definition once the field carries no read restriction', function (): void {
    $data = ($this->validatorWith)([])->validate(
        ($this->definition)(['group_by_field_key' => 'sales_region']),
        $this->objectType,
        ($this->viewer)(),
    );

    expect($data->groupByField?->key)->toBe('sales_region');
});

it('refuses a linked grouping field when the viewer may not view the linked object type', function (): void {
    AccessContext::grant('deals.view');

    $relation = ($this->field)('customer', FieldType::RelationHasMany, [
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $linkedField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('linked-name'),
        'object_type_id' => $this->linkedObjectType->getKey(),
        'key' => 'company_name',
        'field_type' => FieldType::TextShort->value,
        'is_encrypted' => false,
    ]);

    $this->linkedResolver->shouldReceive('resolve')->andReturn(new ReportLinkedFieldBinding(
        'customer.company_name',
        $relation,
        ModelStub::ulid('rel'),
        (string) $this->linkedObjectType->getKey(),
        $linkedField,
    ));

    expect(($this->reasonFor)(
        ($this->definition)(['group_by_field_key' => 'customer.company_name']),
        [],
        [...$this->persisted, $relation],
    ))->toBe(ReportNotExecutableReason::FieldNotReadable);
});

it('accepts a linked grouping field once the viewer holds the view permission of the linked object type', function (): void {
    AccessContext::grant('deals.view', 'companies.view');

    $relation = ($this->field)('customer', FieldType::RelationHasMany, [
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $linkedField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('linked-name'),
        'object_type_id' => $this->linkedObjectType->getKey(),
        'key' => 'company_name',
        'field_type' => FieldType::TextShort->value,
        'is_encrypted' => false,
    ]);

    $binding = new ReportLinkedFieldBinding(
        'customer.company_name',
        $relation,
        ModelStub::ulid('rel'),
        (string) $this->linkedObjectType->getKey(),
        $linkedField,
    );

    $this->linkedResolver->shouldReceive('resolve')->andReturn($binding);

    $data = ($this->validatorWith)([], [...$this->persisted, $relation])->validate(
        ($this->definition)(['group_by_field_key' => 'customer.company_name']),
        $this->objectType,
        ($this->viewer)(),
    );

    expect($data->groupByLink)->toBe($binding)
        ->and($data->groupByField?->key)->toBe('company_name');
});

it('refuses a linked grouping field whose own key the viewer may not read on the linked type', function (): void {
    AccessContext::grant('deals.view', 'companies.view');

    $relation = ($this->field)('customer', FieldType::RelationHasMany, [
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $linkedField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('linked-name'),
        'object_type_id' => $this->linkedObjectType->getKey(),
        'key' => 'company_name',
        'field_type' => FieldType::TextShort->value,
        'is_encrypted' => false,
    ]);

    $this->linkedResolver->shouldReceive('resolve')->andReturn(new ReportLinkedFieldBinding(
        'customer.company_name',
        $relation,
        ModelStub::ulid('rel'),
        (string) $this->linkedObjectType->getKey(),
        $linkedField,
    ));

    expect(($this->reasonFor)(
        ($this->definition)(['group_by_field_key' => 'customer.company_name']),
        ['company_name'],
        [...$this->persisted, $relation],
    ))->toBe(ReportNotExecutableReason::FieldNotReadable);
});

it('refuses a linked grouping field whose linked object type cannot be resolved at all', function (): void {
    AccessContext::grant('deals.view', 'companies.view');

    $relation = ($this->field)('customer', FieldType::RelationHasMany, [
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $linkedField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('linked-name'),
        'object_type_id' => ModelStub::ulid('ghost'),
        'key' => 'company_name',
        'field_type' => FieldType::TextShort->value,
        'is_encrypted' => false,
    ]);

    $this->linkedResolver->shouldReceive('resolve')->andReturn(new ReportLinkedFieldBinding(
        'customer.company_name',
        $relation,
        ModelStub::ulid('rel'),
        ModelStub::ulid('ghost'),
        $linkedField,
    ));

    expect(($this->reasonFor)(
        ($this->definition)(['group_by_field_key' => 'customer.company_name']),
        [],
        [...$this->persisted, $relation],
    ))->toBe(ReportNotExecutableReason::FieldNotReadable);
});

it('refuses an encrypted linked field before it ever asks for a permission', function (): void {
    $resolver = AccessContext::grant('deals.view', 'companies.view');

    $relation = ($this->field)('customer', FieldType::RelationHasMany, [
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $linkedField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('linked-secret'),
        'object_type_id' => $this->linkedObjectType->getKey(),
        'key' => 'payroll',
        'field_type' => FieldType::Money->value,
        'is_encrypted' => true,
    ]);

    $this->linkedResolver->shouldReceive('resolve')->andReturn(new ReportLinkedFieldBinding(
        'customer.payroll',
        $relation,
        ModelStub::ulid('rel'),
        (string) $this->linkedObjectType->getKey(),
        $linkedField,
    ));

    expect(($this->reasonFor)(
        ($this->definition)(['group_by_field_key' => 'customer.payroll']),
        [],
        [...$this->persisted, $relation],
    ))->toBe(ReportNotExecutableReason::EncryptedField)
        ->and($resolver->askedFor)->not->toContain('companies.view');
});

it('refuses a qualified key on the series axis because a series never spans a relation', function (): void {
    expect(($this->reasonFor)(($this->definition)([
        'group_by_field_key' => 'sales_region',
        'series_field_key' => 'customer.company_name',
    ])))->toBe(ReportNotExecutableReason::UnsupportedGrouping);
});

it('refuses a qualified key inside the filter tree instead of treating it as a plain key', function (): void {
    expect(($this->reasonFor)(($this->definition)([
        'filter_definition' => ($this->condition)('customer.company_name'),
    ])))->toBe(ReportNotExecutableReason::UnknownField);
});

it('refuses an averaging aggregation over a linked field because it cannot be decomposed', function (): void {
    AccessContext::grant('deals.view', 'companies.view');

    $relation = ($this->field)('customer', FieldType::RelationHasMany, [
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $linkedField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('linked-amount'),
        'object_type_id' => $this->linkedObjectType->getKey(),
        'key' => 'revenue',
        'field_type' => FieldType::Money->value,
        'is_encrypted' => false,
    ]);

    $this->linkedResolver->shouldReceive('resolve')->andReturn(new ReportLinkedFieldBinding(
        'customer.revenue',
        $relation,
        ModelStub::ulid('rel'),
        (string) $this->linkedObjectType->getKey(),
        $linkedField,
    ));

    expect(($this->reasonFor)(($this->definition)([
        'aggregation_type' => AggregationType::Avg->value,
        'aggregation_field_key' => 'customer.revenue',
    ])))->toBe(ReportNotExecutableReason::UnsupportedAggregation);
});

it('refuses an encrypted field of the primary type with the encryption reason', function (): void {
    expect(($this->reasonFor)(($this->definition)([
        'aggregation_type' => AggregationType::Sum->value,
        'aggregation_field_key' => 'payroll_total',
    ])))->toBe(ReportNotExecutableReason::EncryptedField);
});

it('refuses an unknown field key in every slot instead of silently dropping it', function (): void {
    expect(($this->reasonFor)(($this->definition)(['group_by_field_key' => 'ghost_field'])))
        ->toBe(ReportNotExecutableReason::UnknownField)
        ->and(($this->reasonFor)(($this->definition)([
            'aggregation_type' => AggregationType::Sum->value,
            'aggregation_field_key' => 'ghost_field',
        ])))->toBe(ReportNotExecutableReason::UnknownField)
        ->and(($this->reasonFor)(($this->definition)([
            'group_by_field_key' => 'sales_region',
            'series_field_key' => 'ghost_field',
        ])))->toBe(ReportNotExecutableReason::UnknownField)
        ->and(($this->reasonFor)(($this->definition)([
            'filter_definition' => ($this->condition)('ghost_field'),
        ])))->toBe(ReportNotExecutableReason::UnknownField);
});

it('refuses an aging field as a report dimension', function (): void {
    expect(($this->reasonFor)(($this->definition)(['group_by_field_key' => 'aging_age'])))
        ->toBe(ReportNotExecutableReason::FieldNotFilterable);
});

it('resolves a system field as a grouping dimension without a persisted definition', function (): void {
    $data = ($this->validatorWith)()->validate(
        ($this->definition)(['group_by_field_key' => 'owner_id']),
        $this->objectType,
        ($this->viewer)(),
    );

    expect($data->groupByField?->key)->toBe('owner_id')
        ->and($data->isSystemField('owner_id'))->toBeTrue();
});

it('lets a persisted field win over the system field carrying the same key', function (): void {
    $shadow = ($this->field)('owner_id', FieldType::Money, ['is_encrypted' => true]);

    $validator = ($this->validatorWith)([], [...$this->persisted, $shadow]);

    try {
        $validator->validate(
            ($this->definition)(['group_by_field_key' => 'owner_id']),
            $this->objectType,
            ($this->viewer)(),
        );
    } catch (ReportNotExecutableException $exception) {
        expect($exception->reason)->toBe(ReportNotExecutableReason::EncryptedField);

        return;
    }

    throw new RuntimeException('The shadowing persisted field was ignored.');
});

it('refuses a sum over a text field and resolves a sum over a number field', function (): void {
    expect(($this->reasonFor)(($this->definition)([
        'aggregation_type' => AggregationType::Sum->value,
        'aggregation_field_key' => 'sales_region',
    ])))->toBe(ReportNotExecutableReason::UnsupportedAggregation);

    $data = ($this->validatorWith)()->validate(
        ($this->definition)([
            'aggregation_type' => AggregationType::Sum->value,
            'aggregation_field_key' => 'ordered_quantity',
        ]),
        $this->objectType,
        ($this->viewer)(),
    );

    expect($data->aggregationField?->key)->toBe('ordered_quantity');
});

it('calls a definition malformed when the shape itself cannot hold a report', function (): void {
    expect(($this->reasonFor)(($this->definition)(['aggregation_type' => AggregationType::Sum->value])))
        ->toBe(ReportNotExecutableReason::MalformedDefinition)
        ->and(($this->reasonFor)(($this->definition)(['group_by_bucket' => GroupingBucket::Month->value])))
        ->toBe(ReportNotExecutableReason::MalformedDefinition)
        ->and(($this->reasonFor)(($this->definition)(['series_field_key' => 'sales_region'])))
        ->toBe(ReportNotExecutableReason::MalformedDefinition)
        ->and(($this->reasonFor)(($this->definition)(['aggregation_type' => 'nonsense'])))
        ->toBe(ReportNotExecutableReason::MalformedDefinition)
        ->and(($this->reasonFor)(($this->definition)([
            'group_by_field_key' => 'closed_on',
            'group_by_bucket' => 'fortnight',
        ])))->toBe(ReportNotExecutableReason::MalformedDefinition)
        ->and(($this->reasonFor)(($this->definition)(['filter_definition' => 'not-an-array'])))
        ->toBe(ReportNotExecutableReason::MalformedDefinition)
        ->and(($this->reasonFor)(($this->definition)(['group_by_field_key' => ''])))
        ->toBe(ReportNotExecutableReason::MalformedDefinition);
});

it('refuses a time bucket over a non temporal grouping field', function (): void {
    expect(($this->reasonFor)(($this->definition)([
        'group_by_field_key' => 'sales_region',
        'group_by_bucket' => GroupingBucket::Month->value,
    ])))->toBe(ReportNotExecutableReason::UnsupportedGrouping);
});

it('refuses a filter over a field that is not marked filterable', function (): void {
    expect(($this->reasonFor)(($this->definition)([
        'filter_definition' => ($this->condition)('internal_memo'),
    ])))->toBe(ReportNotExecutableReason::FieldNotFilterable);
});

it('refuses a filter tree deeper than the allowed nesting as an invalid tree', function (): void {
    $tree = ['combinator' => 'and', 'conditions' => [['field' => 'sales_region', 'operator' => 'equals', 'value' => 'x']]];

    for ($depth = 0; $depth < 6; $depth++) {
        $tree = ['combinator' => 'and', 'conditions' => [$tree]];
    }

    expect(($this->reasonFor)(($this->definition)(['filter_definition' => $tree])))
        ->toBe(ReportNotExecutableReason::InvalidFilterTree);
});

it('refuses an operator that the field type cannot carry as an invalid tree', function (): void {
    expect(($this->reasonFor)(($this->definition)([
        'filter_definition' => ($this->condition)('closed_on', 'contains', 'x'),
    ])))->toBe(ReportNotExecutableReason::InvalidFilterTree);
});

it('discloses neither a field key nor a field label in any refusal message', function (): void {
    $secrets = ['gross_amount', 'payroll_total', 'internal_memo', 'ghost_field', 'Sales_region', 'company_name'];

    $refusals = [
        ($this->definition)(['group_by_field_key' => 'ghost_field']),
        ($this->definition)(['aggregation_type' => AggregationType::Sum->value, 'aggregation_field_key' => 'payroll_total']),
        ($this->definition)(['filter_definition' => ($this->condition)('internal_memo')]),
        ($this->definition)(['aggregation_type' => AggregationType::Sum->value, 'aggregation_field_key' => 'sales_region']),
        ($this->definition)(['group_by_field_key' => 'sales_region', 'group_by_bucket' => GroupingBucket::Month->value]),
    ];

    $messages = [];

    foreach ($refusals as $definition) {
        try {
            ($this->validatorWith)()->validate($definition, $this->objectType, ($this->viewer)());
        } catch (ReportNotExecutableException $exception) {
            $messages[] = $exception->getMessage();
        }
    }

    expect($messages)->toHaveCount(count($refusals));

    foreach ($messages as $message) {
        expect($message)->not->toBe('');

        foreach ($secrets as $secret) {
            expect($message)->not->toContain($secret);
        }
    }
});

it('resolves a definition that omits every optional key with an empty filter tree', function (): void {
    $data = ($this->validatorWith)()->validate(
        ['aggregation_type' => AggregationType::Count->value],
        $this->objectType,
        ($this->viewer)(),
    );

    expect($data->filterTree)->toBe([])
        ->and($data->fields)->toBe([])
        ->and($data->aggregation)->toBe(AggregationType::Count);
});

it('never reaches the database while refusing a definition', function (): void {
    $reached = QueryShape::attemptedBy(function (): void {
        try {
            ($this->validatorWith)()->validate(
                ($this->definition)(['group_by_field_key' => 'ghost_field']),
                $this->objectType,
                ($this->viewer)(),
            );
        } catch (ReportNotExecutableException) {
            return;
        }
    });

    expect($reached)->toBeNull();
});
