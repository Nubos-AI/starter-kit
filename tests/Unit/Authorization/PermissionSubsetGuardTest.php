<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);

    /** @var callable(list<string>):Role */
    $this->managerRoleWith = fn (array $permissionNames): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('manager'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'manager',
    ], ['permissions' => ModelStub::collection(Permission::class, [])->merge(array_map(
        fn (string $name): Permission => ModelStub::make(Permission::class, [
            'id' => ModelStub::ulid($name),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $name,
        ]),
        $permissionNames,
    ))]);

    $this->guard = fn (): PermissionSubsetGuard => app(PermissionSubsetGuard::class);

    AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a role that carries a permission the actor does not hold', function (): void {
    $resolver = AccessContext::grant('members.view');

    expect(fn () => ($this->guard)()->assertMayAssignRole(($this->managerRoleWith)(['members.password'])))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toBe(['members.password']);
});

it('lets an actor pass on a role whose permissions they hold themselves', function (): void {
    AccessContext::grant('members.view', 'members.password');

    expect(fn () => ($this->guard)()->assertMayAssignRole(($this->managerRoleWith)(['members.view', 'members.password'])))
        ->not->toThrow(AuthorizationException::class);
});
