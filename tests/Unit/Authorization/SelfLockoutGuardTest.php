<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Authorization\EscalatedAssignmentDirectory;
use App\Support\Authorization\SelfLockoutGuard;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeEscalatedAssignmentDirectory;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->escalatedRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('super-admin-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'super-admin-role',
        'authority' => RoleAuthority::SuperAdmin->value,
    ]);

    $this->plainRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'sales',
        'authority' => null,
    ]);

    $this->scopeAdminRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('scope-admin-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'scope-admin-role',
        'authority' => RoleAuthority::ScopeAdmin->value,
    ]);

    /** @var callable(string, User, ?Role):RoleAssignment */
    $this->assignmentOf = fn (string $seed, User $holder, ?Role $role = null): RoleAssignment => ModelStub::make(RoleAssignment::class, [
        'id' => ModelStub::ulid($seed),
        'role_id' => ($role ?? $this->escalatedRole)->getKey(),
        'model_type' => $holder->getMorphClass(),
        'model_id' => $holder->getKey(),
    ], ['role' => $role ?? $this->escalatedRole]);

    /** @var callable(list<RoleAssignment>):SelfLockoutGuard */
    $this->guardHolding = function (array $assignments): SelfLockoutGuard {
        $directory = new FakeEscalatedAssignmentDirectory($assignments);
        app()->instance(EscalatedAssignmentDirectory::class, $directory);

        $this->directory = $directory;

        return app(SelfLockoutGuard::class);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to revoke the last escalated assignment', function (): void {
    $holder = AccessContext::user($this->tenant, [], 'last-admin');
    $assignment = ($this->assignmentOf)('only-assignment', $holder);

    $guard = ($this->guardHolding)([$assignment]);

    expect(fn () => $guard->assertLastEscalatedAssignmentSurvives($this->escalatedRole, $assignment))
        ->toThrow(SelfLockoutException::class);
});

it('lets one of two escalated assignments go after reading them under a lock', function (): void {
    $first = ($this->assignmentOf)('first-assignment', AccessContext::user($this->tenant, [], 'first-admin'));
    $second = ($this->assignmentOf)('second-assignment', AccessContext::user($this->tenant, [], 'second-admin'));

    ($this->guardHolding)([$first, $second])
        ->assertLastEscalatedAssignmentSurvives($this->escalatedRole, $first);

    expect($this->directory->lockedReads)->toBe(1);
});

it('leaves a role without authority alone', function (): void {
    $assignment = ($this->assignmentOf)('only-assignment', AccessContext::user($this->tenant, [], 'last-admin'));

    $guard = ($this->guardHolding)([$assignment]);

    $guard->assertLastEscalatedAssignmentSurvives($this->plainRole, $assignment);

    expect($this->directory->lockedReads)->toBe(0);
});

it('refuses to downgrade the role the last escalated assignment hangs on', function (): void {
    $assignment = ($this->assignmentOf)('only-assignment', AccessContext::user($this->tenant, [], 'last-admin'));

    $guard = ($this->guardHolding)([$assignment]);

    expect(fn () => $guard->assertEscalationSurvivesRoleChange($this->escalatedRole))
        ->toThrow(SelfLockoutException::class);
});

it('lets a role be downgraded while another role of the same authority stays occupied', function (): void {
    $otherRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('second-super-admin-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'second-super-admin-role',
        'authority' => RoleAuthority::SuperAdmin->value,
    ]);

    ($this->guardHolding)([
        ($this->assignmentOf)('only-assignment', AccessContext::user($this->tenant, [], 'last-admin')),
        ($this->assignmentOf)('survivor', AccessContext::user($this->tenant, [], 'other-admin'), $otherRole),
    ])->assertEscalationSurvivesRoleChange($this->escalatedRole);
})->throwsNoExceptions();

it('refuses to downgrade the last super admin role while only a scope admin role stays occupied', function (): void {
    $guard = ($this->guardHolding)([
        ($this->assignmentOf)('only-assignment', AccessContext::user($this->tenant, [], 'last-admin')),
        ($this->assignmentOf)('survivor', AccessContext::user($this->tenant, [], 'owner'), $this->scopeAdminRole),
    ]);

    expect(fn () => $guard->assertEscalationSurvivesRoleChange($this->escalatedRole))
        ->toThrow(SelfLockoutException::class);
});

it('refuses to revoke the last super admin assignment while only a scope admin remains', function (): void {
    $assignment = ($this->assignmentOf)('only-assignment', AccessContext::user($this->tenant, [], 'last-admin'));

    $guard = ($this->guardHolding)([
        $assignment,
        ($this->assignmentOf)('survivor', AccessContext::user($this->tenant, [], 'owner'), $this->scopeAdminRole),
    ]);

    expect(fn () => $guard->assertLastEscalatedAssignmentSurvives($this->escalatedRole, $assignment))
        ->toThrow(SelfLockoutException::class);
});

it('refuses to remove the last super admin while only a scope admin remains', function (): void {
    $holder = AccessContext::user($this->tenant, [], 'last-admin');

    $guard = ($this->guardHolding)([
        ($this->assignmentOf)('only-assignment', $holder),
        ($this->assignmentOf)('survivor', AccessContext::user($this->tenant, [], 'owner'), $this->scopeAdminRole),
    ]);

    expect(fn () => $guard->assertUserIsNotLastEscalatedHolder($holder))
        ->toThrow(SelfLockoutException::class);
});

it('refuses to remove the last scope admin while only a super admin remains', function (): void {
    $owner = AccessContext::user($this->tenant, [], 'owner');

    $guard = ($this->guardHolding)([
        ($this->assignmentOf)('super-assignment', AccessContext::user($this->tenant, [], 'super-admin')),
        ($this->assignmentOf)('owner-assignment', $owner, $this->scopeAdminRole),
    ]);

    expect(fn () => $guard->assertUserIsNotLastEscalatedHolder($owner))
        ->toThrow(SelfLockoutException::class);
});

it('refuses to remove the last escalated holder', function (): void {
    $holder = AccessContext::user($this->tenant, [], 'last-admin');

    $guard = ($this->guardHolding)([($this->assignmentOf)('only-assignment', $holder)]);

    expect(fn () => $guard->assertUserIsNotLastEscalatedHolder($holder))
        ->toThrow(SelfLockoutException::class);
});

it('lets a holder go while a second escalated holder remains', function (): void {
    $holder = AccessContext::user($this->tenant, [], 'last-admin');
    $other = AccessContext::user($this->tenant, [], 'other-admin');

    ($this->guardHolding)([
        ($this->assignmentOf)('first-assignment', $holder),
        ($this->assignmentOf)('second-assignment', $other),
    ])->assertUserIsNotLastEscalatedHolder($holder);
})->throwsNoExceptions();

it('protects nobody when the tenant carries no escalated assignment at all', function (): void {
    ($this->guardHolding)([])
        ->assertUserIsNotLastEscalatedHolder(AccessContext::user($this->tenant, [], 'plain-user'));
})->throwsNoExceptions();

it('refuses a change that would cost the acting user their role management', function (): void {
    $actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'actor'));
    AccessContext::grant('roles.view');

    $guard = ($this->guardHolding)([]);

    expect(fn () => $guard->assertActingUserRetainsRoleManagement($actor))
        ->toThrow(SelfLockoutException::class);
});

it('lets the acting user through while they keep role management', function (): void {
    $actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'actor'));
    $resolver = AccessContext::grant('roles.update');

    ($this->guardHolding)([])->assertActingUserRetainsRoleManagement($actor);

    expect($resolver->askedFor)->toBe(['roles.update']);
});

it('reports the holders that are the last of their authority', function (): void {
    $superAdmin = AccessContext::user($this->tenant, [], 'last-admin');
    $firstOwner = AccessContext::user($this->tenant, [], 'first-owner');
    $secondOwner = AccessContext::user($this->tenant, [], 'second-owner');

    $keys = ($this->guardHolding)([
        ($this->assignmentOf)('first-assignment', $superAdmin),
        ($this->assignmentOf)('second-assignment', $superAdmin),
        ($this->assignmentOf)('first-owner-assignment', $firstOwner, $this->scopeAdminRole),
        ($this->assignmentOf)('second-owner-assignment', $secondOwner, $this->scopeAdminRole),
    ])->lastEscalatedHolderKeys();

    expect($keys->all())->toBe([(string) $superAdmin->getKey()]);
});
