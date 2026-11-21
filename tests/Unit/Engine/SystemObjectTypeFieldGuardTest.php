<?php

declare(strict_types=1);

use App\Actions\Engine\DeleteFieldDefinitionAction;
use App\Actions\Engine\UpdateFieldDefinitionAction;
use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\SystemObjectTypeGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(bool):ObjectType */
    $this->objectType = fn (bool $isSystem): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid($isSystem ? 'system-type' : 'regular-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'attachments',
        'is_system' => $isSystem,
    ]);

    /** @var callable(bool, string):FieldDefinition */
    $this->field = fn (bool $isSystem, string $key = 'amount'): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => ($this->objectType)($isSystem)->getKey(),
            'key' => $key,
            'field_type' => FieldType::Number,
            'is_required' => false,
            'is_unique' => false,
            'is_encrypted' => false,
            'is_sortable' => true,
            'is_filterable' => true,
        ],
        ['objectType' => ($this->objectType)($isSystem)],
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to manage the fields of a system object type', function (): void {
    expect(fn () => (new SystemObjectTypeGuard)->assertFieldsAreManageable(($this->objectType)(true)))
        ->toThrow(AuthorizationException::class);
});

it('lets the fields of a regular object type be managed', function (): void {
    (new SystemObjectTypeGuard)->assertFieldsAreManageable(($this->objectType)(false));
})->throwsNoExceptions();

it('refuses to update a field of a system object type before it validates anything', function (): void {
    expect(fn (): FieldDefinition => app(UpdateFieldDefinitionAction::class)
        ->execute(($this->field)(true), ['is_required' => true]))
        ->toThrow(AuthorizationException::class);
});

it('refuses to delete a field of a system object type', function (): void {
    expect(function (): void {
        app(DeleteFieldDefinitionAction::class)->execute(($this->field)(true));
    })->toThrow(AuthorizationException::class);
});

it('refuses to delete the name field every object type carries', function (): void {
    expect(function (): void {
        app(DeleteFieldDefinitionAction::class)->execute(($this->field)(false, 'name'));
    })->toThrow(ValidationException::class);
});

it('lets any other field of a regular object type reach its delete statement', function (): void {
    $field = ($this->field)(false, 'note');

    expect(function () use ($field): void {
        app(DeleteFieldDefinitionAction::class)->execute($field);
    })->toThrow(PDOException::class);
});
