<?php

declare(strict_types=1);

use App\Actions\Engine\BulkDeleteActivityTypesAction;
use App\Actions\Engine\BulkDeleteMergeRulesAction;
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

it('refuses an identifier that is not a string and never reaches a row', function (): void {
    $action = app(BulkDeleteActivityTypesAction::class);

    expect(fn (): mixed => $action->execute($this->actor, ['ids' => [17]]))
        ->toThrow(ValidationException::class)
        ->and(QueryShape::attemptedBy(function () use ($action): void {
            try {
                $action->execute($this->actor, ['ids' => [17]]);
            } catch (ValidationException) {
                return;
            }
        }))->toBeNull();
});

it('refuses a payload whose ids are no list at all', function (): void {
    expect(fn (): mixed => app(BulkDeleteActivityTypesAction::class)->execute($this->actor, ['ids' => 'alles']))
        ->toThrow(ValidationException::class);
});

it('reaches the rows only through the global scopes of the model', function (): void {
    $wanted = ModelStub::ulid('bulk-delete-wanted');
    $foreign = ModelStub::ulid('bulk-delete-foreign');

    $shape = QueryShape::attemptedBy(fn (): mixed => app(BulkDeleteActivityTypesAction::class)
        ->execute($this->actor, ['ids' => [$wanted, $foreign]]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('activity_types'))->toBeTrue()
        ->and($shape->isScopedToTenant('activity_types', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('activity_types'))->toBeTrue()
        ->and($shape->hasColumnCondition('activity_types', 'id'))->toBeTrue()
        ->and($shape->hasBinding($wanted))->toBeTrue()
        ->and($shape->hasBinding($foreign))->toBeTrue();
});

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
