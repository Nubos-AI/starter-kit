<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Support\Engine\RollupRecomputer;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('budget-tenant');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->dealTypeId = ModelStub::ulid('budget-deal-type');
    $this->itemTypeId = ModelStub::ulid('budget-item-type');
    $this->activityTypeId = ModelStub::ulid('budget-activity-type');
    $this->itemsRelationshipId = ModelStub::ulid('budget-deal-items');
    $this->activitiesRelationshipId = ModelStub::ulid('budget-deal-activities');

    $openFilter = ['combinator' => 'and', 'conditions' => [
        ['field' => 'status', 'operator' => 'equals', 'value' => 'open'],
    ]];

    $rollups = [
        'items_count' => ['aggregate' => 'count', 'relationship_type_id' => $this->itemsRelationshipId],
        'items_total' => ['aggregate' => 'sum', 'source_field_key' => 'amount', 'relationship_type_id' => $this->itemsRelationshipId],
        'items_average' => ['aggregate' => 'avg', 'source_field_key' => 'amount', 'relationship_type_id' => $this->itemsRelationshipId],
        'items_smallest' => ['aggregate' => 'min', 'source_field_key' => 'amount', 'relationship_type_id' => $this->itemsRelationshipId],
        'items_largest' => ['aggregate' => 'max', 'source_field_key' => 'amount', 'relationship_type_id' => $this->itemsRelationshipId],
        'open_items_count' => ['aggregate' => 'count', 'relationship_type_id' => $this->itemsRelationshipId, 'filter' => $openFilter],
        'open_items_total' => ['aggregate' => 'sum', 'source_field_key' => 'amount', 'relationship_type_id' => $this->itemsRelationshipId, 'filter' => $openFilter],
        'activities_count' => ['aggregate' => 'count', 'relationship_type_id' => $this->activitiesRelationshipId],
        'open_activities_count' => ['aggregate' => 'count', 'relationship_type_id' => $this->activitiesRelationshipId, 'filter' => $openFilter],
    ];

    $this->rollupRows = array_values(array_map(
        fn (string $key, array $config): array => [
            'id' => ModelStub::ulid("budget-rollup-{$key}"),
            'tenant_id' => $this->tenantId,
            'object_type_id' => $this->dealTypeId,
            'key' => $key,
            'field_type' => FieldType::Rollup->value,
            'config' => json_encode($config),
        ],
        array_keys($rollups),
        $rollups,
    ));

    $this->statusFieldOf = fn (string $objectTypeId): array => [[
        'id' => ModelStub::ulid("budget-status-{$objectTypeId}"),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $objectTypeId,
        'key' => 'status',
        'field_type' => FieldType::TextShort->value,
        'is_filterable' => true,
        'is_translatable' => false,
        'is_encrypted' => false,
    ]];

    $this->deal = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('budget-deal'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->dealTypeId,
        'version' => 1,
        'data' => ['title' => 'Budget deal'],
    ]);

    $this->connectionAnswering = fn (bool $hasLinks): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_starts_with($sql, 'with merged_values') || str_starts_with($sql, 'update custom_records') => [[
                'data' => '{"title":"Budget deal","items_count":2}',
            ]],
            str_contains($sql, 'as aggregate') => [['aggregate' => $hasLinks ? '2' : (str_contains($sql, 'COUNT(*)') ? '0' : null)]],
            str_contains($sql, 'from "record_links"') => [['exists' => $hasLinks]],
            str_contains($sql, 'from "relationship_types"') => [[
                'id' => $bindings[0],
                'tenant_id' => $this->tenantId,
                'from_object_type_id' => $this->dealTypeId,
                'to_object_type_id' => $bindings[0] === $this->itemsRelationshipId ? $this->itemTypeId : $this->activityTypeId,
            ]],
            str_contains($sql, 'from "field_definitions"') && in_array($this->dealTypeId, $bindings, true) => $this->rollupRows,
            str_contains($sql, 'from "field_definitions"') && in_array($this->itemTypeId, $bindings, true) => ($this->statusFieldOf)($this->itemTypeId),
            str_contains($sql, 'from "field_definitions"') && in_array($this->activityTypeId, $bindings, true) => ($this->statusFieldOf)($this->activityTypeId),
            default => [],
        },
        static fn (): int => 1,
    );

    $this->statementCount = static fn (StaticQueryConnection $connection): int => count($connection->queries) + count($connection->writtenStatements);

    $this->recordWritesOf = static fn (StaticQueryConnection $connection): array => array_values(array_filter(
        [...$connection->queries, ...$connection->writtenStatements],
        static fn (array $statement): bool => str_contains(strtolower($statement['sql']), 'update custom_records'),
    ));
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('materialises all nine roll-ups of a linked deal in one write and seven statements overall', function (): void {
    $connection = ($this->connectionAnswering)(true);

    app(RollupRecomputer::class)->materializeOwn($this->deal);

    expect(($this->recordWritesOf)($connection))->toHaveCount(1)
        ->and(($this->statementCount)($connection))->toBe(7);
});

it('reads the target fields of a filtered relationship only once however many roll-ups filter over it', function (): void {
    $connection = ($this->connectionAnswering)(true);

    app(RollupRecomputer::class)->materializeOwn($this->deal);

    expect($connection->sqlOf('relationship_types'))->toHaveCount(2)
        ->and(array_filter(
            $connection->queries,
            fn (array $query): bool => str_contains($query['sql'], 'from "field_definitions"')
                && in_array($this->itemTypeId, $query['bindings'], true),
        ))->toHaveCount(1);
});

it('sets the empty values of a deal without links without a single aggregate', function (): void {
    $connection = ($this->connectionAnswering)(false);

    app(RollupRecomputer::class)->materializeOwn($this->deal);

    expect(array_filter(
        $connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], 'count(*)') || str_contains($query['sql'], 'COUNT(*)'),
    ))->toBe([])
        ->and(($this->recordWritesOf)($connection))->toHaveCount(1)
        ->and(($this->statementCount)($connection))->toBe(7);
});

it('writes nothing for a deal without links whose roll-ups already carry their empty values', function (): void {
    $connection = ($this->connectionAnswering)(false);

    $deal = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('budget-deal'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->dealTypeId,
        'version' => 1,
        'data' => ['items_count' => 0, 'open_items_count' => 0, 'activities_count' => 0, 'open_activities_count' => 0],
    ]);

    $changed = app(RollupRecomputer::class)->materializeOwn($deal);

    expect($changed)->toBe([])
        ->and(($this->recordWritesOf)($connection))->toBe([])
        ->and(($this->statementCount)($connection))->toBe(6);
});
