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

    /** @var callable(string, User):RoleAssignment */
    $this->assignmentOf = fn (string $seed, User $holder): RoleAssignment => ModelStub::make(RoleAssignment::class, [
        'id' => ModelStub::ulid($seed),
        'role_id' => $this->escalatedRole->getKey(),
        'model_type' => $holder->getMorphClass(),
        'model_id' => $holder->getKey(),
    ]);

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

it('lets one of two escalated assignments go', function (): void {
    $first = ($this->assignmentOf)('first-assignment', AccessContext::user($this->tenant, [], 'first-admin'));
    $second = ($this->assignmentOf)('second-assignment', AccessContext::user($this->tenant, [], 'second-admin'));

    ($this->guardHolding)([$first, $second])
        ->assertLastEscalatedAssignmentSurvives($this->escalatedRole, $first);
})->throwsNoExceptions();

it('reads the escalated assignments under a lock', function (): void {
    $assignment = ($this->assignmentOf)('only-assignment', AccessContext::user($this->tenant, [], 'last-admin'));

    $guard = ($this->guardHolding)([$assignment, ($this->assignmentOf)('second', AccessContext::user($this->tenant, [], 'other'))]);

    $guard->assertLastEscalatedAssignmentSurvives($this->escalatedRole, $assignment);

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

it('lets a role be downgraded while another escalated role stays occupied', function (): void {
    $otherRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('scope-admin-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'scope-admin-role',
        'authority' => RoleAuthority::ScopeAdmin->value,
    ]);

    $survivor = ModelStub::make(RoleAssignment::class, [
        'id' => ModelStub::ulid('survivor'),
        'role_id' => $otherRole->getKey(),
        'model_type' => (new User)->getMorphClass(),
        'model_id' => ModelStub::ulid('other-admin'),
    ]);

    ($this->guardHolding)([
        ($this->assignmentOf)('only-assignment', AccessContext::user($this->tenant, [], 'last-admin')),
        $survivor,
    ])->assertEscalationSurvivesRoleChange($this->escalatedRole);
})->throwsNoExceptions();

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

it('reports the escalated holders it knows', function (): void {
    $holder = AccessContext::user($this->tenant, [], 'last-admin');

    $keys = ($this->guardHolding)([
        ($this->assignmentOf)('first-assignment', $holder),
        ($this->assignmentOf)('second-assignment', $holder),
    ])->escalatedHolderKeys();

    expect($keys->all())->toBe([(string) $holder->getKey()]);
});
