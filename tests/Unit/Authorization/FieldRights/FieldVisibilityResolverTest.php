<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\Role;
use App\Support\Authorization\FieldVisibilityResolver;
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

    /** @var callable(list<FieldDefinition>, list<FieldPermission>):FieldVisibilityResolver */
    $this->resolverOver = function (array $fields, array $grants): FieldVisibilityResolver {
        $this->directory = (new FakeAuthorizationDirectory)->withFieldGrantRows($grants);

        return new FieldVisibilityResolver(
            StaticObjectTypeFieldLookup::carrying($this->objectTypeId, $fields),
            $this->directory,
        );
    };

    /** @var callable(list<Role>):RoleHolder */
    $this->holderOf = fn (array $roles): RoleHolder => RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        $roles,
        'field-rights-actor',
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('leaves a field nobody holds a row for open for reading and writing', function (): void {
    $open = ($this->field)('revenue');

    $resolver = ($this->resolverOver)([$open], []);
    $user = ($this->holderOf)([($this->role)('sales')]);

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe(['revenue'])
        ->and($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe([])
        ->and($resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId))->toBe([]);
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
    'a user without any role sees every field' => [[], [['sales', false, false]], ['salary'], [], []],
]);

it('lets an escalated authority past every field right without asking for grants', function (): void {
    $restricted = ($this->field)('salary');
    $strangerRole = ($this->role)('finance');

    $resolver = ($this->resolverOver)([$restricted], [($this->grant)($restricted, $strangerRole, false, false)]);
    $user = ($this->holderOf)([($this->role)('super-admin', RoleAuthority::SuperAdmin)]);

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe(['salary'])
        ->and($resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId))->toBe([])
        ->and($this->directory->askedFor)->not->toContain('fieldGrantsOfFields');
});

it('keeps a scope admin past the field rights as well', function (): void {
    $restricted = ($this->field)('salary');

    $resolver = ($this->resolverOver)([$restricted], [($this->grant)($restricted, ($this->role)('finance'), false, false)]);
    $user = ($this->holderOf)([($this->role)('scope-admin', RoleAuthority::ScopeAdmin)]);

    expect($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe([]);
});

it('resolves the field rights once per user and object type', function (): void {
    $restricted = ($this->field)('salary');
    $role = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$restricted], [($this->grant)($restricted, $role, true, false)]);
    $user = ($this->holderOf)([$role]);

    $resolver->readableFieldKeys($user, $this->objectTypeId);
    $resolver->forbiddenReadFieldKeys($user, $this->objectTypeId);
    $resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId);

    expect(array_filter($this->directory->askedFor, static fn (string $call): bool => $call === 'fieldGrantsOfFields'))
        ->toHaveCount(1);
});

it('resolves again when the roles of the user change', function (): void {
    $restricted = ($this->field)('salary');
    $denying = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$restricted], [($this->grant)($restricted, $denying, false, false)]);

    $before = $resolver->readableFieldKeys(($this->holderOf)([$denying]), $this->objectTypeId);
    $after = $resolver->readableFieldKeys(($this->holderOf)([$denying, ($this->role)('finance')]), $this->objectTypeId);

    expect($before)->toBe([])
        ->and($after)->toBe(['salary'])
        ->and(array_filter($this->directory->askedFor, static fn (string $call): bool => $call === 'fieldGrantsOfFields'))
        ->toHaveCount(2);
});

it('reports an object type without any field as fully open and fully empty', function (): void {
    $resolver = ($this->resolverOver)([], []);
    $user = ($this->holderOf)([($this->role)('sales')]);

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe([])
        ->and($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe([])
        ->and($resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId))->toBe([]);
});
