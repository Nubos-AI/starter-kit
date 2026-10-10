<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);

    /** @var callable(string, array<string, mixed>, list<Permission>):Role */
    $this->roleWith = function (string $seed, array $attributes = [], array $permissions = []): Role {
        return ModelStub::make(Role::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $seed,
            ...$attributes,
        ], ['permissions' => ModelStub::collection(Permission::class, [])->merge($permissions)]);
    };

    /** @var callable(string):Permission */
    $this->permission = fn (string $name): Permission => ModelStub::make(Permission::class, [
        'id' => ModelStub::ulid($name),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $name,
    ]);

    $this->salaryField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('salary-field'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('employees'),
        'key' => 'salary',
    ]);

    /** @var callable(Role, bool, bool):FieldPermission */
    $this->restriction = fn (Role $role, bool $read, bool $write): FieldPermission => ModelStub::make(FieldPermission::class, [
        'id' => ModelStub::ulid('salary-restriction-'.$role->name),
        'tenant_id' => $this->tenant->getKey(),
        'role_id' => $role->getKey(),
        'field_definition_id' => $this->salaryField->getKey(),
        'can_read' => $read,
        'can_write' => $write,
    ]);

    $this->guard = fn (): PermissionSubsetGuard => app(PermissionSubsetGuard::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a role carrying an authority to an actor without escalation', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    AccessContext::grant();

    $role = ($this->roleWith)('super', ['authority' => RoleAuthority::SuperAdmin->value]);

    expect(fn () => ($this->guard)()->assertMayAssignRole($role))
        ->toThrow(AuthorizationException::class);
});

it('lets an escalated actor assign a role carrying an authority', function (): void {
    $escalated = ($this->roleWith)('escalating', ['authority' => RoleAuthority::ScopeAdmin->value]);

    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [$escalated]));
    AccessContext::grant();

    $role = ($this->roleWith)('super', ['authority' => RoleAuthority::SuperAdmin->value]);

    ($this->guard)()->assertMayAssignRole($role);

    expect($this->directory->askedFor)->toContain('fieldGrantsOfRole');
});

it('refuses a role that carries a permission the actor does not hold', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    $resolver = AccessContext::grant('members.view');

    $role = ($this->roleWith)('manager', [], [($this->permission)('members.password')]);

    expect(fn () => ($this->guard)()->assertMayAssignRole($role))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toBe(['members.password'])
        ->and($this->directory->askedFor)->not->toContain('fieldGrantsOfRole');
});

it('lets an actor pass on a role whose permissions they hold themselves', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    AccessContext::grant('members.view', 'members.password');

    $role = ($this->roleWith)('manager', [], [
        ($this->permission)('members.view'),
        ($this->permission)('members.password'),
    ]);

    ($this->guard)()->assertMayAssignRole($role);

    expect($this->directory->askedFor)->toContain('fieldGrantsOfRole');
});

it('refuses a role that leaves open a field the actor may not read', function (): void {
    $clerk = ($this->roleWith)('clerk');

    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [$clerk]));
    AccessContext::grant();

    new FakeFieldVisibilityResolver(['salary'], ['salary']);

    $this->directory
        ->withFieldDefinitions([$this->salaryField])
        ->withFieldGrants($clerk, [($this->restriction)($clerk, false, false)]);

    expect(fn () => ($this->guard)()->assertMayAssignRole(($this->roleWith)('manager')))
        ->toThrow(AuthorizationException::class);
});

it('lets an actor assign a role that restricts the field just like their own', function (): void {
    $clerk = ($this->roleWith)('clerk');
    $manager = ($this->roleWith)('manager');

    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [$clerk]));
    AccessContext::grant();

    new FakeFieldVisibilityResolver(['salary'], ['salary']);

    $this->directory
        ->withFieldDefinitions([$this->salaryField])
        ->withFieldGrants($clerk, [($this->restriction)($clerk, false, false)])
        ->withFieldGrants($manager, [($this->restriction)($manager, false, false)]);

    ($this->guard)()->assertMayAssignRole($manager);

    expect($this->directory->askedFor)->toContain('fieldGrantsOfRole');
});

it('lets an actor assign an unrestricted role when another of their roles still opens the field', function (): void {
    $clerk = ($this->roleWith)('clerk');

    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [$clerk, ($this->roleWith)('finance')]));
    AccessContext::grant();

    new FakeFieldVisibilityResolver;

    $this->directory
        ->withFieldDefinitions([$this->salaryField])
        ->withFieldGrants($clerk, [($this->restriction)($clerk, false, false)]);

    ($this->guard)()->assertMayAssignRole(($this->roleWith)('manager'));

    expect($this->directory->askedFor)->toContain('fieldDefinitionsByIds');
});

it('refuses to lift a restriction on a field the actor may not read', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    AccessContext::grant();

    new FakeFieldVisibilityResolver(['salary'], ['salary']);

    $manager = ($this->roleWith)('manager');

    $this->directory
        ->withFieldDefinitions([$this->salaryField])
        ->withFieldGrants($manager, [($this->restriction)($manager, false, false)]);

    expect(fn () => ($this->guard)()->assertMayGrantFieldPermissions($manager, [[
        'field_definition_id' => (string) $this->salaryField->getKey(),
        'can_read' => true,
        'can_write' => false,
    ]]))->toThrow(AuthorizationException::class);
});

it('lets an actor lift a restriction on a field they may read and write', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    AccessContext::grant();

    new FakeFieldVisibilityResolver;

    $manager = ($this->roleWith)('manager');

    $this->directory
        ->withFieldDefinitions([$this->salaryField])
        ->withFieldGrants($manager, [($this->restriction)($manager, false, false)]);

    ($this->guard)()->assertMayGrantFieldPermissions($manager, [[
        'field_definition_id' => (string) $this->salaryField->getKey(),
        'can_read' => true,
        'can_write' => true,
    ]]);

    expect($this->directory->askedFor)->toContain('fieldDefinitionsByIds');
});

it('asks nothing when a sync only restricts a field the role could use before', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    AccessContext::grant();

    new FakeFieldVisibilityResolver(['salary'], ['salary']);

    $manager = ($this->roleWith)('manager');

    $this->directory->withFieldDefinitions([$this->salaryField]);

    ($this->guard)()->assertMayGrantFieldPermissions($manager, [[
        'field_definition_id' => (string) $this->salaryField->getKey(),
        'can_read' => false,
        'can_write' => false,
    ]]);

    expect($this->directory->askedFor)->not->toContain('fieldDefinitionsByIds');
});

it('checks only the permissions a sync newly adds to a role', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    $resolver = AccessContext::grant('members.view');

    $role = ($this->roleWith)('manager');
    $held = ($this->permission)('members.password');
    $added = ($this->permission)('members.view');

    $this->directory
        ->withPermissions([$held, $added])
        ->withRolePermissions($role, [(string) $held->getKey()]);

    ($this->guard)()->assertMayGrantPermissions($role, [
        (string) $held->getKey(),
        (string) $added->getKey(),
    ]);

    expect($resolver->askedFor)->toBe(['members.view']);
});

it('refuses to add a permission the actor does not hold', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    AccessContext::grant('members.view');

    $role = ($this->roleWith)('manager');
    $added = ($this->permission)('members.password');

    $this->directory->withPermissions([$added])->withRolePermissions($role, []);

    expect(fn () => ($this->guard)()->assertMayGrantPermissions($role, [(string) $added->getKey()]))
        ->toThrow(AuthorizationException::class);
});

it('refuses an unknown permission id instead of silently dropping it', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    AccessContext::grant('members.view');

    $role = ($this->roleWith)('manager');

    $this->directory->withRolePermissions($role, []);

    expect(fn () => ($this->guard)()->assertMayGrantPermissions($role, [ModelStub::ulid('ghost')]))
        ->toThrow(AuthorizationException::class);
});

it('asks nothing when a sync only strips permissions', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    $resolver = AccessContext::grant();

    $role = ($this->roleWith)('manager');
    $held = ($this->permission)('members.password');

    $this->directory
        ->withPermissions([$held])
        ->withRolePermissions($role, [(string) $held->getKey()]);

    ($this->guard)()->assertMayGrantPermissions($role, []);

    expect($resolver->askedFor)->toBeEmpty();
});

it('refuses a deny override on a permission the actor does not hold', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    AccessContext::grant('members.view');

    $denied = ($this->permission)('members.password');
    $this->directory->withPermissions([$denied]);

    expect(fn () => ($this->guard)()->assertMayOverridePermissions([(string) $denied->getKey()]))
        ->toThrow(AuthorizationException::class);
});

it('allows a deny override on a permission the actor holds', function (): void {
    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
    $resolver = AccessContext::grant('members.password');

    $denied = ($this->permission)('members.password');
    $this->directory->withPermissions([$denied]);

    ($this->guard)()->assertMayOverridePermissions([(string) $denied->getKey()]);

    expect($resolver->askedFor)->toBe(['members.password']);
});

it('checks nothing outside a request because seeding has no acting user', function (): void {
    Auth::forgetUser();
    $resolver = AccessContext::grant();

    $role = ($this->roleWith)('super', ['authority' => RoleAuthority::SuperAdmin->value]);

    ($this->guard)()->assertMayAssignRole($role);

    expect($resolver->askedFor)->toBeEmpty()
        ->and($this->directory->askedFor)->toBeEmpty();
});
