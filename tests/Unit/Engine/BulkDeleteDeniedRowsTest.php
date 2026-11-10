<?php

declare(strict_types=1);

use App\Actions\Engine\BulkDeleteMergeRulesAction;
use App\Actions\Engine\BulkDeleteObjectTypesAction;
use App\Actions\Engine\DeleteMergeRuleAction;
use App\Actions\Engine\DeleteObjectTypeAction;
use App\Models\MergeRule;
use App\Models\ObjectType;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant));

    $this->objectTypeRow = fn (string $seed, bool $isSystem): array => [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => $seed,
        'key' => $seed,
        'is_system' => $isSystem,
    ];

    $this->installRows = fn (string $table, array $rows): StaticQueryConnection => StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, "from \"{$table}\"") ? $rows : [],
    );

    $this->deletedKeys = new ArrayObject;
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('deletes only the object types the actor may delete and leaves a system object type in place', function (): void {
    AccessContext::grant('object-types.delete');

    ($this->installRows)('object_types', [
        ($this->objectTypeRow)('regular-type', false),
        ($this->objectTypeRow)('system-type', true),
    ]);

    $deletedKeys = $this->deletedKeys;
    $this->mock(DeleteObjectTypeAction::class)
        ->shouldReceive('execute')
        ->andReturnUsing(static function (ObjectType $objectType) use ($deletedKeys): void {
            $deletedKeys[] = (string) $objectType->getKey();
        });

    $deleted = app(BulkDeleteObjectTypesAction::class)->execute($this->actor, [
        'ids' => [ModelStub::ulid('regular-type'), ModelStub::ulid('system-type')],
    ]);

    expect($deletedKeys->getArrayCopy())->toBe([ModelStub::ulid('regular-type')])
        ->and($deleted->modelKeys())->toBe([ModelStub::ulid('regular-type')]);
});

it('deletes nothing at all when the actor may delete none of the rows it named', function (string $actionClass, string $deleteActionClass, string $table, array $grants, bool $isSystem): void {
    AccessContext::grant(...$grants);

    ($this->installRows)($table, [
        ($this->objectTypeRow)('first-row', $isSystem),
        ($this->objectTypeRow)('second-row', $isSystem),
    ]);

    $this->mock($deleteActionClass)->shouldNotReceive('execute');

    $scope = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('merge-scope'),
        'tenant_id' => (string) $this->tenant->getKey(),
    ]);

    $deleted = app($actionClass)->execute(
        $this->actor,
        ['ids' => [ModelStub::ulid('first-row'), ModelStub::ulid('second-row')]],
        $scope,
    );

    expect($deleted)->toBeEmpty();
})->with([
    'object types without the delete permission' => [BulkDeleteObjectTypesAction::class, DeleteObjectTypeAction::class, 'object_types', ['object-types.view'], false],
    'system object types despite the delete permission' => [BulkDeleteObjectTypesAction::class, DeleteObjectTypeAction::class, 'object_types', ['object-types.delete'], true],
    'merge rules without the update permission on object types' => [BulkDeleteMergeRulesAction::class, DeleteMergeRuleAction::class, 'merge_rules', ['object-types.view'], false],
]);

it('deletes every merge rule of the object type once the actor may update object types', function (): void {
    AccessContext::grant('object-types.update');

    ($this->installRows)('merge_rules', [
        ['id' => ModelStub::ulid('rule-one'), 'tenant_id' => (string) $this->tenant->getKey()],
        ['id' => ModelStub::ulid('rule-two'), 'tenant_id' => (string) $this->tenant->getKey()],
    ]);

    $deletedKeys = $this->deletedKeys;
    $this->mock(DeleteMergeRuleAction::class)
        ->shouldReceive('execute')
        ->andReturnUsing(static function (MergeRule $mergeRule) use ($deletedKeys): void {
            $deletedKeys[] = (string) $mergeRule->getKey();
        });

    $scope = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('merge-scope'),
        'tenant_id' => (string) $this->tenant->getKey(),
    ]);

    app(BulkDeleteMergeRulesAction::class)->execute(
        $this->actor,
        ['ids' => [ModelStub::ulid('rule-one'), ModelStub::ulid('rule-two')]],
        $scope,
    );

    expect($deletedKeys->getArrayCopy())->toBe([ModelStub::ulid('rule-one'), ModelStub::ulid('rule-two')]);
});
