<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\Role;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('companies');

    /** @var callable(string, ?RoleAuthority):Role */
    $this->role = fn (string $seed, ?RoleAuthority $authority = null): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'authority' => $authority?->value,
    ]);

    /** @var callable(string):FieldDefinition */
    $this->field = fn (string $key): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'is_encrypted' => false,
    ]);

    /** @var callable(FieldDefinition, Role, bool, bool):FieldPermission */
    $this->grant = fn (FieldDefinition $field, Role $role, bool $read, bool $write): FieldPermission => ModelStub::make(FieldPermission::class, [
        'id' => ModelStub::ulid('grant-'.$field->key.'-'.$role->name),
        'tenant_id' => $this->tenant->getKey(),
        'role_id' => (string) $role->getKey(),
        'field_definition_id' => (string) $field->getKey(),
        'can_read' => $read,
        'can_write' => $write,
    ]);

    $this->childTypeId = ModelStub::ulid('contacts');
    $this->relationshipTypeId = ModelStub::ulid('company-contacts');

    /** @var callable(list<FieldDefinition>, list<FieldPermission>, list<FieldDefinition>):FieldVisibilityResolver */
    $this->resolverOver = function (array $fields, array $grants, array $childFields = []): FieldVisibilityResolver {
        $this->directory = (new FakeAuthorizationDirectory)->withFieldGrantRows($grants);

        $lookup = StaticObjectTypeFieldLookup::carrying($this->objectTypeId, $fields)
            ->withFields($this->childTypeId, $childFields)
            ->withRelationshipTarget($this->relationshipTypeId, $this->childTypeId);

        app()->instance(ObjectTypeFieldLookup::class, $lookup);
        app()->instance(AuthorizationDirectory::class, $this->directory);
        app()->forgetInstance(FieldVisibilityResolver::class);

        return app()->make(FieldVisibilityResolver::class);
    };

    /** @var callable(list<Role>):RoleHolder */
    $this->holderOf = fn (array $roles): RoleHolder => RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        $roles,
        'field-rights-actor',
    );
});

afterEach(function (): void {
    app()->forgetInstance(ObjectTypeFieldLookup::class);
    app()->forgetInstance(AuthorizationDirectory::class);
    AccessContext::forgetTenant();
});

it('combines the field rows of every role the user holds into the readable and the forbidden keys', function (array $userRoleSeeds, array $rows, array $readable, array $forbiddenRead, array $forbiddenWrite): void {
    $restricted = ($this->field)('salary');

    $resolver = ($this->resolverOver)([$restricted], array_map(
        fn (array $row): FieldPermission => ($this->grant)($restricted, ($this->role)($row[0]), $row[1], $row[2]),
        $rows,
    ));

    $user = ($this->holderOf)(array_map(fn (string $seed): Role => ($this->role)($seed), $userRoleSeeds));

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe($readable)
        ->and($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe($forbiddenRead)
        ->and($resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId))->toBe($forbiddenWrite);
})->with([
    'a row of another role restricts nothing' => [['sales'], [['finance', false, false]], ['salary'], [], []],
    'a row that keeps reading withholds writing alone' => [['sales'], [['sales', true, false]], ['salary'], [], ['salary']],
    'a row that revokes both withholds reading and writing' => [['sales'], [['sales', false, false]], [], ['salary'], ['salary']],
    'a second role without a row opens the field' => [['sales', 'finance'], [['sales', false, false]], ['salary'], [], []],
    'a second role keeping its rights by row opens the field' => [['sales', 'finance'], [['sales', false, false], ['finance', true, true]], ['salary'], [], []],
    'every role revoking keeps the field closed' => [['sales', 'support'], [['sales', false, false], ['support', false, false]], [], ['salary'], ['salary']],
    'the read of one role combines with the write of another' => [['sales', 'finance'], [['sales', true, false], ['finance', false, true]], ['salary'], [], []],
    'a user without any role is held by a row that revokes both' => [[], [['sales', false, false]], [], ['salary'], ['salary']],
    'a user without any role is held by a row that withholds writing' => [[], [['sales', true, false]], ['salary'], [], ['salary']],
    'a user without any role keeps a field whose rows keep every right' => [[], [['sales', true, true]], ['salary'], [], []],
]);
