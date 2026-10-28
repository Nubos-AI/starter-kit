<?php

declare(strict_types=1);

use App\Actions\Engine\CreateFieldDefinitionAction;
use App\Actions\Engine\UpdateFieldDefinitionAction;
use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\IndexRegistry;
use Illuminate\Validation\DatabasePresenceVerifier;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('deferral-object-type');

    $this->indexes = Mockery::mock(IndexRegistry::class);
    app()->instance(IndexRegistry::class, $this->indexes);

    $objectTypeRow = [
        'id' => $this->objectTypeId,
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => 'projects',
        'is_system' => false,
        'storage_strategy' => 'generic',
    ];

    $this->connection = StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'count(*) as "aggregate"') => [['aggregate' => 1]],
            str_contains($sql, 'from "object_types"') => [$objectTypeRow],
            default => [],
        },
        static fn (): int => 1,
    );

    app('validator')->setPresenceVerifier(new DatabasePresenceVerifier($this->connection));

    $this->field = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('deferral-field'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => 'industry',
        'field_type' => FieldType::TextShort,
        'is_required' => false,
        'is_unique' => false,
        'is_searchable' => false,
        'is_translatable' => false,
        'is_encrypted' => false,
        'is_sortable' => true,
        'is_filterable' => true,
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance(IndexRegistry::class);
    AccessContext::forgetTenant();
    Mockery::close();
});

it('builds the indexes of a new field only once the field is committed', function (): void {
    $indexed = [];
    $this->indexes->shouldReceive('ensureSortAndFilterIndexes')
        ->andReturnUsing(static function (FieldDefinition $field) use (&$indexed): void {
            $indexed[] = $field->key;
        });

    ObjectType::withoutEvents(fn (): FieldDefinition => FieldDefinition::withoutEvents(
        fn (): FieldDefinition => app(CreateFieldDefinitionAction::class)->execute([
            'object_type_id' => $this->objectTypeId,
            'key' => 'industry',
            'field_type' => FieldType::TextShort->value,
        ]),
    ));

    expect($this->connection->writtenSqlOf('field_definitions'))->toHaveCount(1)
        ->and($indexed)->toBe([])
        ->and($this->connection->deferredAfterCommit)->toHaveCount(1);

    ($this->connection->deferredAfterCommit[0])();

    expect($indexed)->toBe(['industry']);
});

it('syncs the indexes of a changed field only once the change is committed', function (): void {
    $synced = [];
    $this->indexes->shouldReceive('syncSortAndFilterIndexes')
        ->andReturnUsing(static function (FieldDefinition $field) use (&$synced): void {
            $synced[] = [$field->key, $field->is_sortable];
        });

    FieldDefinition::withoutEvents(fn (): FieldDefinition => app(UpdateFieldDefinitionAction::class)
        ->execute($this->field, ['is_sortable' => false]));

    expect($this->connection->writtenSqlOf('field_definitions'))->toHaveCount(1)
        ->and($synced)->toBe([])
        ->and($this->connection->deferredAfterCommit)->toHaveCount(1);

    ($this->connection->deferredAfterCommit[0])();

    expect($synced)->toBe([['industry', false]]);
});

it('defers no index work for a change that leaves every index relevant flag alone', function (): void {
    $this->indexes->shouldNotReceive('syncSortAndFilterIndexes');

    FieldDefinition::withoutEvents(fn (): FieldDefinition => app(UpdateFieldDefinitionAction::class)
        ->execute($this->field, ['is_required' => true]));

    expect($this->connection->deferredAfterCommit)->toBe([]);
});
