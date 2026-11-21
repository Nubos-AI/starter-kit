<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Users\UserStatus;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Authorization\EscalatedAssignmentDirectory;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\PermissionSubsetGuard;
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
            fn (User $holder): RoleAssignment => ModelStub::make(RoleAssignment::class, [
                'id' => ModelStub::ulid('assignment-'.$holder->getKey()),
                'role_id' => ModelStub::ulid('super-admin-role'),
                'model_type' => (new User)->getMorphClass(),
                'model_id' => (string) $holder->getKey(),
            ], ['role' => ModelStub::make(Role::class, [
                'id' => ModelStub::ulid('super-admin-role'),
                'tenant_id' => $this->tenant->getKey(),
                'name' => 'super-admin-role',
                'authority' => RoleAuthority::SuperAdmin->value,
            ])]),
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
    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    $this->subsetGuard->shouldReceive('steerableAccountKeys')->byDefault()->andReturnUsing(
        static fn (User $actor, array $userKeys): array => $userKeys,
    );
    $this->subsetGuard->shouldNotReceive('maySteerAccount');

    $this->rowsFor = fn (array $users, User $actingUser): array => (new ManageableUserRows(
        app(ManagedUserResolver::class),
        app(SelfLockoutGuard::class),
        $this->directory,
        $this->subsetGuard,
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

it('keeps the last super admin from being deleted while only a scope admin remains', function (): void {
    $superAdmin = ($this->member)('last-admin');
    $owner = ($this->member)('owner');

    app()->instance(EscalatedAssignmentDirectory::class, new FakeEscalatedAssignmentDirectory(array_map(
        fn (array $holding): RoleAssignment => ModelStub::make(RoleAssignment::class, [
            'id' => ModelStub::ulid('assignment-'.$holding[0]->getKey()),
            'role_id' => ModelStub::ulid($holding[1]->value),
            'model_type' => (new User)->getMorphClass(),
            'model_id' => (string) $holding[0]->getKey(),
        ], ['role' => ModelStub::make(Role::class, [
            'id' => ModelStub::ulid($holding[1]->value),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $holding[1]->value,
            'authority' => $holding[1]->value,
        ])]),
        [[$superAdmin, RoleAuthority::SuperAdmin], [$owner, RoleAuthority::ScopeAdmin]],
    )));
    $this->directory->withEscalation($superAdmin)->withEscalation($owner);

    $actor = ($this->actor)(true);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');

    $row = ($this->rowsFor)([$superAdmin], $actor)[0];

    expect($row['can_delete'])->toBeFalse()
        ->and($row['can_block'])->toBeFalse();
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

it('keeps an account holding permissions beyond the actor out of reach and says why', function (): void {
    $admin = ($this->member)('powerful-admin');
    ($this->escalatedHolders)([]);

    $actor = ($this->actor)(false);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');
    $this->subsetGuard->shouldReceive('steerableAccountKeys')->once()->withArgs(static fn (User $actingUser, array $keys): bool => $actingUser === $actor && $keys === [(string) $admin->getKey()])->andReturn([]);

    $row = ($this->rowsFor)([$admin], $actor)[0];

    expect([$row['can_update'], $row['can_delete'], $row['can_block']])->toBe([false, false, false])
        ->and($row['delete_reason'])->toBe(__('i18n.backend.support.users.manageable_user_rows.holds_more_rights_than_you'))
        ->and($row['block_reason'])->toBe($row['delete_reason']);
});

it('judges the rights of every reachable account in a single pass instead of once per row', function (): void {
    $colleague = ($this->member)('colleague');
    $powerful = ($this->member)('powerful-admin');
    $service = ($this->member)('service-account', ['is_service' => true]);
    ($this->escalatedHolders)([]);

    $actor = ($this->actor)(false);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');
    $this->subsetGuard->shouldReceive('steerableAccountKeys')
        ->once()
        ->withArgs(static fn (User $actingUser, array $keys): bool => $actingUser === $actor && $keys === [(string) $colleague->getKey(), (string) $powerful->getKey()])
        ->andReturn([(string) $colleague->getKey()]);

    $rows = ($this->rowsFor)([$colleague, $powerful, $service, $actor], $actor);

    expect(array_map(static fn (array $row): bool => $row['can_update'], $rows))->toBe([true, false, false, true]);
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

it('withholds the resend offer for an invited member outside the invite reach of the acting user', function (): void {
    $nearTeamId = ModelStub::ulid('near-team');
    $near = ($this->member)('near-invited', ['status' => UserStatus::Invited->value]);
    $far = ($this->member)('far-invited', ['status' => UserStatus::Invited->value]);
    ($this->escalatedHolders)([]);
    $this->directory->withTeams($near, [$nearTeamId])->withTeams($far, [ModelStub::ulid('far-team')]);

    $actor = ($this->actor)(false);
    FakeManagedUserResolver::reachingTeams([$nearTeamId]);
    GateSpy::allowing('invite');

    $rows = ($this->rowsFor)([$near, $far], $actor);

    expect([$rows[0]['can_resend_invitation'], $rows[1]['can_resend_invitation']])->toBe([true, false]);
});

it('withholds the resend offer for an invited member holding permissions beyond the actor', function (): void {
    $invited = ($this->member)('invited-admin', ['status' => UserStatus::Invited->value]);
    ($this->escalatedHolders)([]);

    $actor = ($this->actor)(false);
    FakeManagedUserResolver::reachingTheTenant();
    GateSpy::allowing('invite');
    $this->subsetGuard->shouldReceive('steerableAccountKeys')->once()->andReturn([]);

    expect(($this->rowsFor)([$invited], $actor)[0]['can_resend_invitation'])->toBeFalse();
});

it('grants each management action only through the reach of its own ability', function (string $reachingAbility, array $expected): void {
    $colleague = ($this->member)('colleague');
    ($this->escalatedHolders)([]);

    $actor = ($this->actor)(true);
    GateSpy::allowing('invite');

    app()->instance(ManagedUserResolver::class, new class($reachingAbility) extends ManagedUserResolver
    {
        public function __construct(private readonly string $reachingAbility) {}

        /**
         * @return array{tenant: bool, teams: list<string>}
         */
        public function reachOf(User $actingUser, string $ability): array
        {
            return ['tenant' => $ability === $this->reachingAbility, 'teams' => []];
        }
    });

    $row = ($this->rowsFor)([$colleague], $actor)[0];

    expect([$row['can_update'], $row['can_delete'], $row['can_block']])->toBe($expected);
})->with([
    'update reach only' => ['members.update', [true, false, false]],
    'remove reach only' => ['members.remove', [false, true, false]],
    'block reach only' => ['members.block', [false, false, true]],
]);
