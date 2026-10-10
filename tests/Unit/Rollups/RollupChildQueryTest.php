<?php

declare(strict_types=1);

use App\DTOs\Engine\RecordTreeNode;
use App\DTOs\Engine\RecordTreeResult;
use App\Enums\CustomFields\FieldType;
use App\Exceptions\Engine\IncompleteRollupChildSetException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\RecordTreeQuery;
use App\Support\Engine\RollupChildResolver;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('tenant');
    $this->foreignTenantId = ModelStub::ulid('foreign-tenant');
    $this->childTypeId = ModelStub::ulid('child-type');
    $this->relationshipId = ModelStub::ulid('relationship');

    $this->anchor = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('anchor'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('parent-type'),
        'data' => [],
    ]);

    $this->targetFields = new Collection([
        ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('field-status'),
            'object_type_id' => $this->childTypeId,
            'key' => 'status',
            'field_type' => FieldType::TextShort,
            'is_filterable' => true,
            'is_translatable' => false,
            'is_encrypted' => false,
        ]),
    ]);

    $this->treeQuery = Mockery::mock(RecordTreeQuery::class);
    $this->lookup = Mockery::mock(ObjectTypeFieldLookup::class);

    /** @var callable(list<string>, bool):void */
    $this->descendantsAre = function (array $recordIds, bool $isTruncated = false): void {
        $this->treeQuery->shouldReceive('descendantsOf')
            ->andReturn(new RecordTreeResult(
                array_map(
                    fn (string $recordId): RecordTreeNode => new RecordTreeNode($recordId, $this->childTypeId, 1),
                    $recordIds,
                ),
                $isTruncated,
                false,
            ));
    };

    /** @var callable():RollupChildResolver */
    $this->resolver = fn (): RollupChildResolver => new RollupChildResolver(
        $this->treeQuery,
        app(RecordFilterCompiler::class),
        app(FilterFieldKeyCollector::class),
        $this->lookup,
    );

    /** @var callable(array<string, mixed>):FieldDefinition */
    $this->rollup = fn (array $config): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('rollup'),
        'object_type_id' => (string) $this->anchor->getAttribute('object_type_id'),
        'key' => 'total',
        'field_type' => FieldType::Rollup,
        'config' => $config,
    ]);

    /** @var callable(array<string, mixed>):QueryShape */
    $this->shapeOf = fn (array $config): QueryShape => QueryShape::of(
        ($this->resolver)()->resolve(($this->rollup)($config), $this->anchor),
    );
});

it('pins the aggregated set to the tenant of the anchor record even though the tenant scope is lifted', function (): void {
    $shape = ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => $this->relationshipId,
    ]);

    expect($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->isScopedToTenant('custom_records', $this->tenantId))->toBeTrue()
        ->and($shape->hasBinding($this->foreignTenantId))->toBeFalse()
        ->and($shape->blocksEveryRow())->toBeFalse();
});

it('never aggregates a child the tenant already deleted', function (): void {
    $shape = ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => $this->relationshipId,
    ]);

    expect($shape->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('reads the direct children from the record links of the same tenant and relationship type', function (): void {
    $shape = ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => $this->relationshipId,
    ]);

    expect($shape->sql)->toContain('from "record_links"')
        ->and($shape->sql)->toContain('"record_links"."to_record_id"')
        ->and($shape->sql)->toContain('"record_links"."from_record_id" =')
        ->and($shape->sql)->toContain('"record_links"."tenant_id" =')
        ->and($shape->sql)->toContain('"record_links"."relationship_type_id" =')
        ->and($shape->bindings)->toContain((string) $this->anchor->getKey())
        ->and($shape->bindings)->toContain($this->relationshipId);
});

it('reads every link of the anchor when the roll-up names no relationship type', function (): void {
    $shape = ($this->shapeOf)(['aggregate' => 'sum', 'source_field_key' => 'amount']);

    expect($shape->sql)->toContain('"record_links"."from_record_id" =')
        ->and($shape->sql)->not->toContain('"record_links"."relationship_type_id" =')
        ->and($shape->isScopedToTenant('custom_records', $this->tenantId))->toBeTrue();
});

it('aggregates every descendant of a subtree roll-up and never the anchor itself', function (): void {
    $first = ModelStub::ulid('descendant-1');
    $second = ModelStub::ulid('descendant-2');

    ($this->descendantsAre)([(string) $this->anchor->getKey(), $first, $second]);

    $shape = ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'scope' => 'subtree',
        'relationship_type_id' => $this->relationshipId,
    ]);

    expect($shape->bindings)->toContain($first)
        ->and($shape->bindings)->toContain($second)
        ->and($shape->bindings)->not->toContain((string) $this->anchor->getKey())
        ->and($shape->sql)->not->toContain('from "record_links"');
});

it('counts a descendant reachable over two paths exactly once', function (): void {
    $shared = ModelStub::ulid('shared-descendant');

    ($this->descendantsAre)([$shared, $shared]);

    $shape = ($this->shapeOf)([
        'aggregate' => 'count',
        'scope' => 'subtree',
        'relationship_type_id' => $this->relationshipId,
    ]);

    expect(array_count_values(array_map('strval', $shape->bindings))[$shared])->toBe(1);
});

it('refuses a subtree roll-up that names no relationship type instead of aggregating everything', function (): void {
    expect(fn (): mixed => ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'scope' => 'subtree',
    ]))->toThrow(IncompleteRollupChildSetException::class);
});

it('refuses a subtree whose descendants were cut off at the traversal ceiling', function (): void {
    ($this->descendantsAre)([ModelStub::ulid('descendant-1')], true);

    expect(fn (): mixed => ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'scope' => 'subtree',
        'relationship_type_id' => $this->relationshipId,
    ]))->toThrow(IncompleteRollupChildSetException::class);
});

it('refuses a filtered roll-up whose relationship type is no longer resolvable', function (): void {
    $this->lookup->shouldReceive('relationshipTarget')->with($this->relationshipId)->andReturnNull();

    expect(fn (): mixed => ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => $this->relationshipId,
        'filter' => ['combinator' => 'and', 'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ]],
    ]))->toThrow(IncompleteRollupChildSetException::class);
});

it('refuses a filter naming a field the target object type no longer has', function (): void {
    $this->lookup->shouldReceive('relationshipTarget')->with($this->relationshipId)->andReturn($this->childTypeId);
    $this->lookup->shouldReceive('fields')->with($this->childTypeId)->andReturn($this->targetFields);

    expect(fn (): mixed => ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => $this->relationshipId,
        'filter' => ['combinator' => 'and', 'conditions' => [
            ['field' => 'region', 'operator' => 'equals', 'value' => 'north'],
        ]],
    ]))->toThrow(IncompleteRollupChildSetException::class);
});

it('narrows the aggregated set by the configured filter', function (): void {
    $this->lookup->shouldReceive('relationshipTarget')->with($this->relationshipId)->andReturn($this->childTypeId);
    $this->lookup->shouldReceive('fields')->with($this->childTypeId)->andReturn($this->targetFields);

    $shape = ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => $this->relationshipId,
        'filter' => ['combinator' => 'and', 'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ]],
    ]);

    expect($shape->sql)->toContain("data->>'status'")
        ->and($shape->bindings)->toContain('active');
});

it('binds a filter value carrying an sql statement as a literal', function (): void {
    $payload = "'; DROP TABLE custom_records; --";

    $this->lookup->shouldReceive('relationshipTarget')->with($this->relationshipId)->andReturn($this->childTypeId);
    $this->lookup->shouldReceive('fields')->with($this->childTypeId)->andReturn($this->targetFields);

    $shape = ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => $this->relationshipId,
        'filter' => ['combinator' => 'and', 'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => $payload],
        ]],
    ]);

    expect(strtolower($shape->sql))->not->toContain('drop table')
        ->and($shape->bindings)->toContain($payload);
});

it('never reads the target fields when the roll-up carries no filter', function (): void {
    $this->lookup->shouldNotReceive('relationshipTarget');
    $this->lookup->shouldNotReceive('fields');

    $shape = ($this->shapeOf)([
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'relationship_type_id' => $this->relationshipId,
    ]);

    expect($shape->sql)->not->toContain('data->>');
});
