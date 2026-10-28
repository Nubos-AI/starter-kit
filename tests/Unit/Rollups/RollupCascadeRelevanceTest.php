<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\RollupRecomputer;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('relevance-tenant');
    $this->childTypeId = ModelStub::ulid('relevance-child-type');
    $this->sourceFieldId = ModelStub::ulid('relevance-source-field');
    $this->dependencies = [];
    $this->formulaOrder = [];

    $this->connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Nubos']],
            str_contains($sql, 'from "field_definitions"') => [['id' => $this->sourceFieldId]],
            str_contains($sql, 'from "field_dependencies"') => $this->dependencies,
            default => [],
        },
    );

    $computedWriter = Mockery::mock(ComputedFieldWriter::class);
    $computedWriter->shouldReceive('orderFor')->andReturnUsing(fn (): array => $this->formulaOrder);
    app()->instance(ComputedFieldWriter::class, $computedWriter);

    $this->recomputer = app(RollupRecomputer::class);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance('current_tenant');
    Mockery::close();
});

it('finds nothing to cascade when no roll-up and no formula depends on the changed fields', function (): void {
    expect($this->recomputer->isCascadeRelevant($this->tenantId, $this->childTypeId, ['name']))->toBeFalse()
        ->and($this->connection->sqlOf('field_dependencies'))->toHaveCount(1);
});

it('cascades when a roll-up depends on a changed field', function (): void {
    $this->dependencies = [[
        'id' => ModelStub::ulid('relevance-dependency'),
        'tenant_id' => $this->tenantId,
        'rollup_field_id' => ModelStub::ulid('relevance-rollup'),
        'depends_on_field_id' => $this->sourceFieldId,
        'relationship_type_id' => ModelStub::ulid('relevance-relationship'),
    ]];

    expect($this->recomputer->isCascadeRelevant($this->tenantId, $this->childTypeId, ['amount']))->toBeTrue();
});

it('cascades without asking for roll-up dependencies when a formula of the record reads a changed field', function (): void {
    $this->formulaOrder = [ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('relevance-formula'),
        'key' => 'total',
        'field_type' => FieldType::Computed,
    ])];

    expect($this->recomputer->isCascadeRelevant($this->tenantId, $this->childTypeId, ['amount']))->toBeTrue()
        ->and($this->connection->sqlOf('field_dependencies'))->toBe([]);
});

it('asks for the dependencies of the changed keys within the tenant of the change', function (): void {
    $this->recomputer->isCascadeRelevant($this->tenantId, $this->childTypeId, ['amount', 'status']);

    $fieldQuery = collect($this->connection->queries)
        ->first(static fn (array $query): bool => str_contains($query['sql'], 'from "field_definitions"'));

    expect($fieldQuery['bindings'])->toBe([$this->childTypeId, 'amount', 'status', $this->tenantId]);
});
