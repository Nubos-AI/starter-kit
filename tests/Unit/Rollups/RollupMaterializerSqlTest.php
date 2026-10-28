<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Handlers\CustomFields\RollupFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\RecordDataPatcher;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\RecordTreeQuery;
use App\Support\Engine\RollupChildResolver;
use App\Support\Engine\RollupMaterializer;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('materializer-tenant');
    $this->relationshipId = ModelStub::ulid('materializer-relationship');
    $this->otherRelationshipId = ModelStub::ulid('materializer-other-relationship');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('materializer-anchor'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('materializer-parent-type'),
        'data' => ['title' => 'Anchor', 'items_count' => 1, 'items_total' => 5],
    ]);
    $this->recordId = (string) $this->record->getKey();

    $resolver = new RollupChildResolver(
        Mockery::mock(RecordTreeQuery::class),
        app(RecordFilterCompiler::class),
        app(FilterFieldKeyCollector::class),
        Mockery::mock(ObjectTypeFieldLookup::class),
    );

    $this->materializer = new RollupMaterializer(new RollupFieldHandler($resolver), $resolver, new RecordDataPatcher);

    $this->rollup = fn (string $key, array $config): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("materializer-rollup-{$key}"),
        'object_type_id' => (string) $this->record->getAttribute('object_type_id'),
        'key' => $key,
        'field_type' => FieldType::Rollup,
        'config' => $config,
    ]);

    $this->countAndSum = fn (): Collection => new Collection([
        ($this->rollup)('items_count', ['aggregate' => 'count', 'relationship_type_id' => $this->relationshipId]),
        ($this->rollup)('items_total', ['aggregate' => 'sum', 'source_field_key' => 'amount', 'relationship_type_id' => $this->relationshipId]),
    ]);

    $this->childSet = 'from "custom_records" where "custom_records"."tenant_id" = ? and "custom_records"."id" in (select "record_links"."to_record_id" from "record_links" where "record_links"."from_record_id" = ? and "record_links"."tenant_id" = ? and "record_links"."relationship_type_id" = ?) and "custom_records"."deleted_at" is null';

    $this->objectBase = "(case when jsonb_typeof(custom_records.data) = 'object' then custom_records.data else '{}'::jsonb end)";

    $this->keyedChanges = static fn (array $fields): array => array_map(
        static fn (FieldDefinition $field): string => $field->key,
        $fields,
    );

    $this->returning = fn (?string $data): StaticQueryConnection => StaticQueryConnection::install(
        static fn (string $sql): array => $data === null ? [] : [['data' => $data]],
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Mockery::close();
});

it('aggregates every roll-up of the record inside one update that writes only a changed row', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->materializer->materialize($this->record, ($this->countAndSum)()));

    $merged = "{$this->objectBase} || merged_values.data";

    expect($shape?->sql)->toBe(
        'with merged_values as (select jsonb_build_object('
        ."?::text, (select COUNT(*) as aggregate {$this->childSet}), "
        ."?::text, (select SUM((custom_records.data->>?)::numeric) as aggregate {$this->childSet})"
        .') as data) '
        ."update custom_records set data = {$merged} from merged_values "
        .'where custom_records.id = ? and custom_records.tenant_id = ? '
        ."and custom_records.data is distinct from ({$merged}) "
        .'returning custom_records.data'
    )->and($shape?->bindings)->toBe([
        'items_count', $this->tenantId, $this->recordId, $this->tenantId, $this->relationshipId,
        'items_total', 'amount', $this->tenantId, $this->recordId, $this->tenantId, $this->relationshipId,
        $this->recordId, $this->tenantId,
    ]);
});

it('splits a record with more roll-ups than one json object call accepts into concatenated objects', function (): void {
    $fields = new Collection(array_map(
        fn (int $index): FieldDefinition => ($this->rollup)("count_{$index}", ['aggregate' => 'count', 'relationship_type_id' => $this->relationshipId]),
        range(1, 51),
    ));

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->materializer->materialize($this->record, $fields));

    expect(substr_count((string) $shape?->sql, 'jsonb_build_object('))->toBe(2)
        ->and((string) $shape?->sql)->toContain("{$this->childSet})) || jsonb_build_object(?::text, (select COUNT(*)")
        ->and(substr_count((string) $shape?->sql, '?::text, (select COUNT(*)'))->toBe(51);
});

it('adopts the written data into the model and reports only the roll-ups whose value moved', function (): void {
    $connection = ($this->returning)('{"title":"Anchor","items_count":2,"items_total":5}');

    $materialization = $this->materializer->materialize($this->record, ($this->countAndSum)());

    expect(($this->keyedChanges)($materialization->changedFields))->toBe(['items_count'])
        ->and($this->record->data)->toBe(['title' => 'Anchor', 'items_count' => 2, 'items_total' => 5])
        ->and($this->record->isDirty('data'))->toBeFalse()
        ->and($connection->queries)->toHaveCount(1);
});

it('keeps a whole aggregate an integer and a fractional one a float when adopting the row', function (): void {
    ($this->returning)('{"title":"Anchor","items_count":3,"items_total":4.5}');

    $this->materializer->materialize($this->record, ($this->countAndSum)());

    expect($this->record->data['items_count'])->toBe(3)
        ->and($this->record->data['items_total'])->toBe(4.5);
});

it('reports nothing and keeps the model when the database found every value already in place', function (): void {
    ($this->returning)(null);

    $materialization = $this->materializer->materialize($this->record, ($this->countAndSum)());

    expect($materialization->changedFields)->toBe([])
        ->and($this->record->data)->toBe(['title' => 'Anchor', 'items_count' => 1, 'items_total' => 5]);
});

it('leaves a roll-up with an unresolvable child set out of the statement and hands it back', function (): void {
    $broken = ($this->rollup)('tree_total', ['aggregate' => 'sum', 'source_field_key' => 'amount', 'scope' => 'subtree']);
    $fields = ($this->countAndSum)()->push($broken);

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->materializer->materialize($this->record, $fields));

    expect($shape?->bindings)->not->toContain('tree_total');

    $connection = ($this->returning)('{"title":"Anchor","items_count":1,"items_total":5}');
    $materialization = $this->materializer->materialize($this->record, $fields);

    expect(($this->keyedChanges)($materialization->incompleteFields))->toBe(['tree_total'])
        ->and($connection->queries)->toHaveCount(1);
});

it('sends no statement at all when not one roll-up of the record is resolvable', function (): void {
    $connection = ($this->returning)('{}');

    $materialization = $this->materializer->materialize($this->record, new Collection([
        ($this->rollup)('tree_total', ['aggregate' => 'sum', 'source_field_key' => 'amount', 'scope' => 'subtree']),
    ]));

    expect($connection->queries)->toBe([])
        ->and(($this->keyedChanges)($materialization->incompleteFields))->toBe(['tree_total']);
});

it('writes the empty values of a record without children in one statement without aggregating', function (): void {
    $record = ModelStub::make(CustomRecord::class, [
        'id' => $this->recordId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->record->getAttribute('object_type_id'),
        'data' => ['title' => 'Anchor'],
    ]);

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->materializer->materializeEmpty($record, ($this->countAndSum)()));

    expect($shape?->sql)->toBe(
        "update custom_records set data = {$this->objectBase} || ?::jsonb "
        .'where custom_records.id = ? and custom_records.tenant_id = ? '
        .'returning custom_records.data'
    )->and($shape?->bindings)->toBe(['{"items_count":0,"items_total":null}', $this->recordId, $this->tenantId]);
});

it('reports the counting roll-up of a new record as changed once its zero is written', function (): void {
    $record = ModelStub::make(CustomRecord::class, [
        'id' => $this->recordId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->record->getAttribute('object_type_id'),
        'data' => ['title' => 'Anchor'],
    ]);
    ($this->returning)('{"title":"Anchor","items_count":0,"items_total":null}');

    $materialization = $this->materializer->materializeEmpty($record, ($this->countAndSum)());

    expect(($this->keyedChanges)($materialization->changedFields))->toBe(['items_count'])
        ->and($record->data)->toBe(['title' => 'Anchor', 'items_count' => 0, 'items_total' => null]);
});

it('writes nothing for a record without children whose roll-ups already hold their empty values', function (): void {
    $record = ModelStub::make(CustomRecord::class, [
        'id' => $this->recordId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->record->getAttribute('object_type_id'),
        'data' => ['items_count' => 0],
    ]);
    $connection = ($this->returning)('{}');

    $materialization = $this->materializer->materializeEmpty($record, ($this->countAndSum)());

    expect($materialization->changedFields)->toBe([])
        ->and($connection->queries)->toBe([]);
});

it('keeps an unresolvable roll-up of a record without children at its previous value', function (): void {
    $record = ModelStub::make(CustomRecord::class, [
        'id' => $this->recordId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->record->getAttribute('object_type_id'),
        'data' => ['items_count' => 0],
    ]);
    $connection = ($this->returning)('{}');

    $materialization = $this->materializer->materializeEmpty($record, new Collection([
        ($this->rollup)('items_count', ['aggregate' => 'count', 'relationship_type_id' => $this->relationshipId]),
        ($this->rollup)('tree_count', ['aggregate' => 'count', 'scope' => 'subtree']),
    ]));

    expect(($this->keyedChanges)($materialization->incompleteFields))->toBe(['tree_count'])
        ->and($connection->queries)->toBe([]);
});

it('looks for an outgoing link of the relationship types the roll-ups aggregate over within the tenant', function (): void {
    $fields = ($this->countAndSum)()->push(
        ($this->rollup)('activities_count', ['aggregate' => 'count', 'relationship_type_id' => $this->otherRelationshipId]),
    );

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->materializer->hasChildLinks($this->record, $fields));

    expect($shape?->sql)->toBe('select exists(select * from "record_links" where "record_links"."tenant_id" = ? and "record_links"."from_record_id" = ? and "record_links"."relationship_type_id" in (?, ?)) as "exists"')
        ->and($shape?->bindings)->toBe([$this->tenantId, $this->recordId, $this->relationshipId, $this->otherRelationshipId]);
});

it('looks for any outgoing link once a roll-up names no relationship type', function (): void {
    $fields = ($this->countAndSum)()->push(($this->rollup)('everything', ['aggregate' => 'count']));

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->materializer->hasChildLinks($this->record, $fields));

    expect($shape?->sql)->toBe('select exists(select * from "record_links" where "record_links"."tenant_id" = ? and "record_links"."from_record_id" = ?) as "exists"')
        ->and($shape?->bindings)->toBe([$this->tenantId, $this->recordId]);
});
