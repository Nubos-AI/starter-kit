<?php

declare(strict_types=1);

use App\DTOs\Engine\RollupMaterialization;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\RollupMaterializer;
use App\Support\Engine\RollupRecomputer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('rollup-tenant');
    $this->childTypeId = ModelStub::ulid('rollup-child-type');
    $this->parentTypeId = ModelStub::ulid('rollup-parent-type');
    $this->childRecordId = ModelStub::ulid('rollup-child-record');
    $this->parentRecordId = ModelStub::ulid('rollup-parent-record');
    $this->sourceFieldId = ModelStub::ulid('rollup-source-field');
    $this->rollupFieldId = ModelStub::ulid('rollup-field');
    $this->relationshipTypeId = ModelStub::ulid('rollup-relationship-type');

    $this->materializer = Mockery::mock(RollupMaterializer::class);
    $this->computedWriter = Mockery::mock(ComputedFieldWriter::class);
    $this->computedWriter->shouldReceive('orderFor')->andReturn([])->byDefault();

    $this->recomputer = new RollupRecomputer($this->materializer, $this->computedWriter);

    /** @var callable(string, list<string>):StaticQueryConnection */
    $this->connectionWith = fn (string $rollupFieldType, array $parentIds): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Nubos']],
            str_contains($sql, 'from "field_definitions"') => in_array($this->rollupFieldId, $bindings, true)
                ? [[
                    'id' => $this->rollupFieldId,
                    'tenant_id' => $this->tenantId,
                    'object_type_id' => $this->parentTypeId,
                    'key' => 'total',
                    'field_type' => $rollupFieldType,
                    'config' => '{"relationship_type_id":"'.$this->relationshipTypeId.'"}',
                ]]
                : (in_array($this->childTypeId, $bindings, true)
                    ? [[
                        'id' => $this->sourceFieldId,
                        'tenant_id' => $this->tenantId,
                        'object_type_id' => $this->childTypeId,
                        'key' => 'amount',
                        'field_type' => FieldType::Number->value,
                    ]]
                    : []),
            str_contains($sql, 'from "field_dependencies"') => in_array($this->sourceFieldId, $bindings, true)
                ? [[
                    'id' => ModelStub::ulid('rollup-dependency'),
                    'tenant_id' => $this->tenantId,
                    'rollup_field_id' => $this->rollupFieldId,
                    'depends_on_field_id' => $this->sourceFieldId,
                    'relationship_type_id' => $this->relationshipTypeId,
                ]]
                : [],
            str_contains($sql, 'from "record_links"') => array_map(
                fn (string $parentId): array => ['from_record_id' => $parentId],
                $parentIds,
            ),
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

    /** @var callable():void */
    $this->recompute = fn (): mixed => $this->recomputer->recompute(
        $this->tenantId,
        $this->childTypeId,
        $this->childRecordId,
        ['amount'],
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance('current_tenant');
    Mockery::close();
});

it('materialises the dependent roll-up on every parent the changed child hangs under', function (): void {
    ($this->connectionWith)(FieldType::Rollup->value, [$this->parentRecordId]);

    $materialised = [];
    $this->materializer->shouldReceive('materialize')
        ->once()
        ->andReturnUsing(function (CustomRecord $record, Collection $fields) use (&$materialised): RollupMaterialization {
            $materialised[] = [
                (string) $record->getKey(),
                $fields->map(static fn (FieldDefinition $field): string => (string) $field->getKey())->all(),
            ];

            return new RollupMaterialization($fields->all(), []);
        });

    ($this->recompute)();

    expect($materialised)->toBe([[$this->parentRecordId, [$this->rollupFieldId]]]);
});

it('leaves a dependency alone whose target field is no longer a roll-up and looks for no parent', function (): void {
    $connection = ($this->connectionWith)(FieldType::Number->value, [$this->parentRecordId]);
    $this->materializer->shouldNotReceive('materialize');

    ($this->recompute)();

    expect($connection->sqlOf('record_links'))->toBe([])
        ->and($connection->sqlOf('custom_records'))->toBe([]);
});

it('materialises the same parent and roll-up pair only once even when two links lead to it', function (): void {
    $connection = ($this->connectionWith)(FieldType::Rollup->value, [$this->parentRecordId, $this->parentRecordId]);
    $this->materializer->shouldReceive('materialize')->once()->andReturn(new RollupMaterialization([], []));

    ($this->recompute)();

    expect($connection->sqlOf('custom_records'))->toHaveCount(1);
});

it('never leaves the tenant it was handed when that tenant is unknown', function (): void {
    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );
    $this->materializer->shouldNotReceive('materialize');

    ($this->recompute)();

    expect($connection->sqlOf('field_definitions'))->toBe([]);
});

it('writes the formula fields a cascade frame can afford in one pass and leaves the rest to the budget warning', function (): void {
    ($this->connectionWith)(FieldType::Rollup->value, []);
    config()->set('engine.rollups.max_computed_fields_per_pass', 2);

    $formulas = array_map(
        fn (string $key): FieldDefinition => ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid("rollup-formula-{$key}"),
            'object_type_id' => $this->childTypeId,
            'key' => $key,
            'field_type' => FieldType::Computed,
        ]),
        ['gross', 'tax', 'weighted'],
    );

    $this->computedWriter->shouldReceive('orderFor')->with($this->childTypeId, ['amount'])->andReturn($formulas);

    $written = [];
    $this->computedWriter->shouldReceive('write')
        ->once()
        ->andReturnUsing(function (CustomRecord $record, array $fields) use (&$written): array {
            $written = array_map(static fn (FieldDefinition $field): string => $field->key, $fields);

            return [];
        });

    Log::spy();

    ($this->recompute)();

    expect($written)->toBe(['gross', 'tax']);

    Log::shouldHaveReceived('warning')
        ->with('Stopped materialising formula fields because the recompute pass exhausted its budget.', Mockery::type('array'))
        ->once();
});
