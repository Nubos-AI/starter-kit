<?php

declare(strict_types=1);

use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Reports\ReportLinkedFieldResolver;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    $this->resolver = app(ReportLinkedFieldResolver::class);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('deals'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
    ]);

    $this->linkedObjectTypeId = ModelStub::ulid('companies');
    $this->relationshipTypeId = ModelStub::ulid('rel');

    /** @var callable(string, FieldType):FieldDefinition */
    $this->linkedField = fn (string $key, FieldType $type): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('linked-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->linkedObjectTypeId,
        'key' => $key,
        'field_type' => $type->value,
        'is_translatable' => false,
        'is_encrypted' => false,
        'is_sortable' => false,
        'is_filterable' => false,
    ]);

    /** @var callable(FieldDefinition):ReportLinkedFieldBinding */
    $this->binding = fn (FieldDefinition $linked): ReportLinkedFieldBinding => new ReportLinkedFieldBinding(
        'customer.'.$linked->key,
        ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('relation-customer'),
            'object_type_id' => $this->objectType->getKey(),
            'key' => 'customer',
            'field_type' => FieldType::RelationHasMany->value,
        ]),
        $this->relationshipTypeId,
        $this->linkedObjectTypeId,
        $linked,
    );

    /** @var callable(string):ReportNotExecutableReason */
    $this->reasonFor = function (string $qualifiedKey): ReportNotExecutableReason {
        try {
            $this->resolver->resolve($qualifiedKey, $this->objectType);
        } catch (ReportNotExecutableException $exception) {
            return $exception->reason;
        }

        throw new RuntimeException("The key [{$qualifiedKey}] was accepted although a refusal was expected.");
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('recognises a qualified key by its single separator', function (): void {
    expect($this->resolver->isQualified('customer.company_name'))->toBeTrue()
        ->and($this->resolver->isQualified('sales_region'))->toBeFalse();
});

it('refuses a qualified key whose segments are not plain field keys before it ever queries', function (): void {
    $malformed = [
        'customer.company.name',
        'Customer.company_name',
        'customer.Company_Name',
        '1customer.company_name',
        'customer.',
        '.company_name',
        "customer.company_name'; drop table custom_records; --",
    ];

    foreach ($malformed as $key) {
        $reached = QueryShape::attemptedBy(function () use ($key): void {
            try {
                $this->resolver->resolve($key, $this->objectType);
            } catch (ReportNotExecutableException) {
                return;
            }
        });

        expect(($this->reasonFor)($key))->toBe(ReportNotExecutableReason::UnknownField)
            ->and($reached)->toBeNull();
    }
});

it('looks the relation field up inside the primary object type by its key', function (): void {
    $shape = QueryShape::attemptedBy(
        fn (): ReportLinkedFieldBinding => $this->resolver->resolve('customer.company_name', $this->objectType),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->targets('field_definitions'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->hasBinding('customer'))->toBeTrue();
});

it('correlates the group subquery over the forward link column and takes the smallest value', function (): void {
    $shape = QueryShape::of($this->resolver->groupSubquery(
        ($this->binding)(($this->linkedField)('company_name', FieldType::TextShort)),
        null,
    ));

    expect($shape->sql)->toContain('MIN(')
        ->and($shape->sql)->toContain('record_links')
        ->and($shape->sql)->toContain('"record_links"."from_record_id" = "custom_records"."id"')
        ->and($shape->sql)->toContain('"record_links"."tenant_id" = "custom_records"."tenant_id"')
        ->and($shape->hasBinding($this->relationshipTypeId))->toBeTrue()
        ->and($shape->sql)->not->toContain(' join ');
});

it('buckets the group subquery when the grouping carries a bucket', function (): void {
    $shape = QueryShape::of($this->resolver->groupSubquery(
        ($this->binding)(($this->linkedField)('closed_on', FieldType::Date)),
        GroupingBucket::Month,
    ));

    expect($shape->sql)->toContain("date_trunc('month'");
});

it('narrows the linked subquery to the linked object type and hides its deleted rows', function (): void {
    $shape = QueryShape::of($this->resolver->aggregateSubquery(
        ($this->binding)(($this->linkedField)('revenue', FieldType::Money)),
        AggregationType::Sum,
    ));

    expect($shape->hasBinding($this->linkedObjectTypeId))->toBeTrue()
        ->and($shape->sql)->toContain('"custom_records"."deleted_at" is null');
});

it('counts the unusable linked values only where the aggregation reads a value', function (): void {
    $numeric = $this->resolver->discardedSubquery(
        ($this->binding)(($this->linkedField)('revenue', FieldType::Money)),
        AggregationType::Sum,
    );

    $temporal = $this->resolver->discardedSubquery(
        ($this->binding)(($this->linkedField)('closed_on', FieldType::Date)),
        AggregationType::Min,
    );

    expect($numeric)->toBeInstanceOf(QueryBuilder::class)
        ->and($temporal)->toBeInstanceOf(QueryBuilder::class);

    foreach ([AggregationType::Count, AggregationType::DistinctCount] as $aggregation) {
        expect($this->resolver->discardedSubquery(
            ($this->binding)(($this->linkedField)('revenue', FieldType::Money)),
            $aggregation,
        ))->toBeNull();
    }
});
