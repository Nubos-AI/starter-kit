<?php

declare(strict_types=1);

use App\Actions\Engine\BulkDeleteActivityTypesAction;
use App\Actions\Engine\BulkDeleteMergeRulesAction;
use App\Actions\Engine\BulkDeleteObjectTypesAction;
use App\Actions\Engine\BulkDeleteRelationshipTypesAction;
use App\Actions\Engine\BulkDeleteReminderTypesAction;
use App\Models\ObjectType;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('bulk-delete-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'key' => 'deals',
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses an identifier list that is no list of identifiers and never reaches a row', function (string $actionClass, mixed $ids): void {
    $action = app($actionClass);

    expect(fn (): mixed => $action->execute($this->actor, ['ids' => $ids]))
        ->toThrow(ValidationException::class)
        ->and(QueryShape::attemptedBy(function () use ($action, $ids): void {
            try {
                $action->execute($this->actor, ['ids' => $ids]);
            } catch (ValidationException) {
                return;
            }
        }))->toBeNull();
})->with([
    'identifier that is not a string' => [BulkDeleteActivityTypesAction::class, [17]],
    'ids that are no list at all' => [BulkDeleteActivityTypesAction::class, 'alles'],
    'nested identifier list' => [BulkDeleteObjectTypesAction::class, ['nested' => ['x']]],
]);

it('reaches the rows only through the global scopes of the model and never beyond the identifiers it was given', function (string $actionClass, string $table): void {
    $wanted = ModelStub::ulid('bulk-delete-wanted');
    $foreign = ModelStub::ulid('bulk-delete-foreign');
    $action = app($actionClass);

    $shape = QueryShape::attemptedBy(fn (): mixed => $action->execute($this->actor, ['ids' => [$wanted, $foreign]]));
    $narrowShape = QueryShape::attemptedBy(fn (): mixed => $action->execute($this->actor, ['ids' => [$wanted]]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets($table))->toBeTrue()
        ->and($shape->isScopedToTenant($table, (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted($table))->toBeTrue()
        ->and($shape->hasColumnCondition($table, 'id'))->toBeTrue()
        ->and($shape->hasBinding($wanted))->toBeTrue()
        ->and($shape->hasBinding($foreign))->toBeTrue()
        ->and($narrowShape?->isKeyedTo($table, $wanted))->toBeTrue()
        ->and($narrowShape?->hasBinding($foreign))->toBeFalse();
})->with([
    'object types' => [BulkDeleteObjectTypesAction::class, 'object_types'],
    'activity types' => [BulkDeleteActivityTypesAction::class, 'activity_types'],
    'reminder types' => [BulkDeleteReminderTypesAction::class, 'reminder_types'],
    'relationship types' => [BulkDeleteRelationshipTypesAction::class, 'relationship_types'],
]);

it('keeps a scoped bulk delete inside the object type it was handed', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => app(BulkDeleteMergeRulesAction::class)->execute(
        $this->actor,
        ['ids' => [ModelStub::ulid('bulk-delete-rule')]],
        $this->objectType,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('merge_rules'))->toBeTrue()
        ->and($shape->sql)->toContain('"object_type_id" = ?')
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->isScopedToTenant('merge_rules', (string) $this->tenant->getKey()))->toBeTrue();
});

it('lets no row through when a scoped bulk delete is handed no scope', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => app(BulkDeleteMergeRulesAction::class)->execute(
        $this->actor,
        ['ids' => [ModelStub::ulid('bulk-delete-rule')]],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"object_type_id" is null');
});
