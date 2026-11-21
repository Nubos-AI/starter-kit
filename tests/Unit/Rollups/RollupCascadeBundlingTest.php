<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\RollupRecomputer;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('bundling-tenant');
    $this->childTypeId = ModelStub::ulid('bundling-child-type');
    $this->parentTypeId = ModelStub::ulid('bundling-parent-type');
    $this->childRecordId = ModelStub::ulid('bundling-child-record');
    $this->parentRecordId = ModelStub::ulid('bundling-parent-record');
    $this->sourceFieldId = ModelStub::ulid('bundling-source-field');
    $this->relationshipTypeId = ModelStub::ulid('bundling-relationship-type');
    $this->rollupIds = [
        'total' => ModelStub::ulid('bundling-rollup-total'),
        'largest' => ModelStub::ulid('bundling-rollup-largest'),
    ];

    $this->rollupRow = fn (string $key): array => [
        'id' => $this->rollupIds[$key],
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->parentTypeId,
        'key' => $key,
        'field_type' => FieldType::Rollup->value,
        'config' => json_encode([
            'aggregate' => $key === 'total' ? 'sum' : 'max',
            'source_field_key' => 'amount',
            'relationship_type_id' => $this->relationshipTypeId,
        ]),
    ];

    $this->connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_starts_with($sql, 'with merged_values') => [['data' => '{"total":7,"largest":7}']],
            str_contains($sql, 'as aggregate') => [['aggregate' => '7']],
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Nubos']],
            str_contains($sql, 'from "field_definitions"') && in_array($this->rollupIds['total'], $bindings, true) => [($this->rollupRow)('total')],
            str_contains($sql, 'from "field_definitions"') && in_array($this->rollupIds['largest'], $bindings, true) => [($this->rollupRow)('largest')],
            str_contains($sql, 'from "field_definitions"') && in_array($this->childTypeId, $bindings, true) => [['id' => $this->sourceFieldId]],
            str_contains($sql, 'from "field_dependencies"') && in_array($this->sourceFieldId, $bindings, true) => array_map(
                fn (string $rollupId): array => [
                    'id' => ModelStub::ulid("bundling-dependency-{$rollupId}"),
                    'tenant_id' => $this->tenantId,
                    'rollup_field_id' => $rollupId,
                    'depends_on_field_id' => $this->sourceFieldId,
                    'relationship_type_id' => $this->relationshipTypeId,
                ],
                array_values($this->rollupIds),
            ),
            str_contains($sql, 'from "record_links"') => [['from_record_id' => $this->parentRecordId]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => $this->parentRecordId,
                'tenant_id' => $this->tenantId,
                'object_type_id' => $this->parentTypeId,
                'version' => 1,
                'data' => '{}',
            ]],
            default => [],
        },
        static fn (): int => 1,
    );

    $this->frames = [];
    $computedWriter = Mockery::mock(ComputedFieldWriter::class);
    $computedWriter->shouldReceive('orderFor')->andReturnUsing(function (string $objectTypeId, array $keys): array {
        $this->frames[] = [$objectTypeId, $keys];

        return [];
    });
    app()->instance(ComputedFieldWriter::class, $computedWriter);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance('current_tenant');
    Mockery::close();
});

it('materialises every roll-up a parent receives from one changed child in a single write', function (): void {
    app(RollupRecomputer::class)->recompute($this->tenantId, $this->childTypeId, $this->childRecordId, ['amount']);

    $writes = array_filter(
        [...$this->connection->queries, ...$this->connection->writtenStatements],
        static fn (array $statement): bool => str_contains(strtolower($statement['sql']), 'update custom_records'),
    );

    expect($writes)->toHaveCount(1)
        ->and($this->connection->sqlOf('custom_records'))->toHaveCount(2);
});

it('still walks on from the parent once per materialised roll-up with that roll-up as its changed key', function (): void {
    app(RollupRecomputer::class)->recompute($this->tenantId, $this->childTypeId, $this->childRecordId, ['amount']);

    expect($this->frames)->toBe([
        [$this->childTypeId, ['amount']],
        [$this->parentTypeId, ['total']],
        [$this->parentTypeId, ['largest']],
    ]);
});
