<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Users\UserStatus;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Authorization\EscalatedAssignmentDirectory;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\SelfLockoutGuard;
use App\Support\Users\ManageableUserRows;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeEscalatedAssignmentDirectory;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticManageableUserDirectory;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->directory = new StaticManageableUserDirectory;

    /** @var callable(string, array<string, mixed>):User */
    $this->member = fn (string $seed, array $attributes = []): User => AccessContext::user($this->tenant, [
        'status' => UserStatus::Accepted->value,
        'is_service' => false,
        'email' => $seed.'@example.test',
        ...$attributes,
    ], $seed);

    /** @var callable(list<User>):void */
    $this->escalatedHolders = function (array $holders): void {
        $assignments = array_map(
            static fn (User $holder): RoleAssignment => ModelStub::make(RoleAssignment::class, [
                'id' => ModelStub::ulid('assignment-'.$holder->getKey()),
                'role_id' => ModelStub::ulid('super-admin-role'),
                'model_type' => (new User)->getMorphClass(),
                'model_id' => (string) $holder->getKey(),
            ]),
            $holders,
        );

        app()->instance(EscalatedAssignmentDirectory::class, new FakeEscalatedAssignmentDirectory($assignments));
    };

    /** @var callable(bool):RoleHolder */
    $this->actor = function (bool $escalated = false): RoleHolder {
        $roles = $escalated
            ? [ModelStub::make(Role::class, [
                'id' => ModelStub::ulid('super-admin-role'),
                'tenant_id' => $this->tenant->getKey(),
                'name' => 'super-admin-role',
                'authority' => RoleAuthority::SuperAdmin->value,
            ])]
            : [];

        return AccessContext::actAs(RoleHolder::make([
            'tenant_id' => $this->tenant->getKey(),
            'status' => UserStatus::Accepted->value,
            'is_service' => false,
            'email' => 'actor@example.test',
        ], $roles, 'rows-actor'));
    };

    /** @var callable(list<User>, User):array<int, array<string, mixed>> */
    $this->rowsFor = fn (array $users, User $actingUser): array => (new ManageableUserRows(
        app(ManagedUserResolver::class),
        app(SelfLockoutGuard::class),
        $this->directory,
    ))->forActingUser(new EloquentCollection($users), $actingUser);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('keeps the last escalated holder from being deleted or blocked and says why', function (): void {
    $lastAdmin = ($this->member)('last-admin');
    ($this->escalatedHolders)([$lastAdmin]);
    $this->directory->withEscalation($lastAdmin);

    $actor = ($this->actor)(true);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');

    $row = ($this->rowsFor)([$lastAdmin], $actor)[0];

    expect($row['can_delete'])->toBeFalse()
        ->and($row['can_block'])->toBeFalse()
        ->and($row['delete_reason'])->toBe(__('i18n.backend.support.users.manageable_user_rows.last_administrator_promote_another_person_first'))
        ->and($row['block_reason'])->toBe($row['delete_reason']);
});

it('lets an escalated holder go as soon as a second one exists', function (): void {
    $admin = ($this->member)('first-admin');
    $other = ($this->member)('second-admin');
    ($this->escalatedHolders)([$admin, $other]);
    $this->directory->withEscalation($admin)->withEscalation($other);

    $actor = ($this->actor)(true);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');

    $row = ($this->rowsFor)([$admin], $actor)[0];

    expect($row['can_delete'])->toBeTrue()
        ->and($row['can_block'])->toBeTrue()
        ->and($row['delete_reason'])->toBeNull()
        ->and($row['block_reason'])->toBeNull();
});

it('never offers the acting user their own deletion or blocking', function (): void {
    ($this->escalatedHolders)([]);
    $actor = ($this->actor)(true);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');

    $row = ($this->rowsFor)([$actor], $actor)[0];

    expect($row['can_delete'])->toBeFalse()
        ->and($row['can_block'])->toBeFalse()
        ->and($row['delete_reason'])->toBe(__('i18n.backend.support.users.manageable_user_rows.your_own_account_delete_it_in_your_profile'))
        ->and($row['block_reason'])->toBe(__('i18n.backend.support.users.manageable_user_rows.your_own_account_you_cannot_block_yourself'));
});

it('leaves a row the acting user cannot reach without a flag and without a reason', function (): void {
    $stranger = ($this->member)('stranger');
    ($this->escalatedHolders)([]);
    $this->directory->withTeams($stranger, [ModelStub::ulid('far-team')]);

    $actor = ($this->actor)(false);
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('near-team')]);
    GateSpy::allowing('invite');

    $row = ($this->rowsFor)([$stranger], $actor)[0];

    expect($row['can_update'])->toBeFalse()
        ->and($row['can_delete'])->toBeFalse()
        ->and($row['can_block'])->toBeFalse()
        ->and($row['delete_reason'])->toBeNull()
        ->and($row['block_reason'])->toBeNull();
});

it('opens a row once it sits in a team the acting user reaches', function (): void {
    $nearTeamId = ModelStub::ulid('near-team');
    $colleague = ($this->member)('colleague');
    ($this->escalatedHolders)([]);
    $this->directory->withTeams($colleague, [$nearTeamId]);

    $actor = ($this->actor)(false);
    FakeManagedUserResolver::reachingTeams([$nearTeamId]);
    GateSpy::allowing('invite');

    $row = ($this->rowsFor)([$colleague], $actor)[0];

    expect($row['can_update'])->toBeTrue()
        ->and($row['can_delete'])->toBeTrue()
        ->and($row['can_block'])->toBeTrue();
});

it('shields a service account from every management flag', function (): void {
    $service = ($this->member)('service-account', ['is_service' => true]);
    ($this->escalatedHolders)([]);

    $actor = ($this->actor)(true);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');

    $row = ($this->rowsFor)([$service], $actor)[0];

    expect($row['can_update'])->toBeFalse()
        ->and($row['can_delete'])->toBeFalse()
        ->and($row['can_block'])->toBeFalse();
});

it('keeps an escalated target out of reach of an acting user without authority', function (): void {
    $admin = ($this->member)('other-admin');
    $second = ($this->member)('second-admin');
    ($this->escalatedHolders)([$admin, $second]);
    $this->directory->withEscalation($admin);

    $actor = ($this->actor)(false);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');

    $row = ($this->rowsFor)([$admin], $actor)[0];

    expect($row['is_escalated'])->toBeTrue()
        ->and($row['can_update'])->toBeFalse();
});

it('offers to resend the invitation only to an invited member and only with the invite ability', function (): void {
    $invited = ($this->member)('invited-member', ['status' => UserStatus::Invited->value]);
    $accepted = ($this->member)('accepted-member');
    ($this->escalatedHolders)([]);

    $actor = ($this->actor)(true);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');

    $rows = ($this->rowsFor)([$invited, $accepted], $actor);

    expect($rows[0]['can_resend_invitation'])->toBeTrue()
        ->and($rows[1]['can_resend_invitation'])->toBeFalse();
});

it('withholds the resend offer from an acting user who may not invite', function (): void {
    $invited = ($this->member)('invited-member', ['status' => UserStatus::Invited->value]);
    ($this->escalatedHolders)([]);

    $actor = ($this->actor)(true);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing();

    expect(($this->rowsFor)([$invited], $actor)[0]['can_resend_invitation'])->toBeFalse();
});

it('asks the reach for each management ability separately', function (): void {
    $colleague = ($this->member)('colleague');
    ($this->escalatedHolders)([]);

    $actor = ($this->actor)(true);
    $reach = FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');

    ($this->rowsFor)([$colleague], $actor);

    expect($reach->askedFor)->toBe(['members.update', 'members.remove', 'members.block']);
});
