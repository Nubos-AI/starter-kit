<?php

declare(strict_types=1);

use App\Actions\Authorization\AssignRoleAction;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Authorization\SelfLockoutGuard;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\ScopedPermissionResolver;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->ability = 'members.update';

    $this->role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('privilege-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'privilege-role',
    ]);

    $this->target = AccessContext::user($this->tenant, [], 'privilege-target');

    $this->assignmentRow = [
        'id' => ModelStub::ulid('privilege-assignment'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'user_id' => (string) $this->target->getKey(),
        'role_id' => (string) $this->role->getKey(),
        'scope_type' => null,
        'scope_id' => null,
    ];

    /** @var callable(list<array<string, mixed>>):StaticQueryConnection */
    $this->answering = static fn (array $assignments): StaticQueryConnection => StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "role_assignments"') ? $assignments : [],
        static fn (): int => 1,
    );

    $this->lockoutGuard = Mockery::mock(SelfLockoutGuard::class);
    $this->auditor = Mockery::mock(AdminArtifactAuditor::class);
    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    $this->subsetGuard->shouldReceive('assertMayAssignRole')->byDefault();

    $this->assignRole = new AssignRoleAction($this->lockoutGuard, $this->auditor, $this->subsetGuard);

    /** @var callable(list<string>, bool):ManagedUserResolver */
    $this->managedResolver = function (array $scopedTeamIds, bool $tenantWide): ManagedUserResolver {
        $this->actor = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'privilege-actor');
        $this->actor->setRelation('tenant', $this->tenant);

        $teams = array_map(fn (string $seed): Team => ModelStub::make(Team::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $seed,
            'ancestor_team_ids' => [],
            'descendant_team_ids' => [],
        ]), $scopedTeamIds);

        $abilities = $tenantWide
            ? [(string) $this->tenant->getKey() => [$this->ability]]
            : array_reduce(
                $teams,
                fn (array $carry, Team $team): array => [...$carry, (string) $team->getKey() => [$this->ability]],
                [],
            );

        return new ManagedUserResolver(
            ScopedPermissionResolver::install($abilities),
            (new FakeAuthorizationDirectory)->withScopedTeams($this->actor, $teams),
        );
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('audits a role assignment only when the write actually created one', function (): void {
    ($this->answering)([$this->assignmentRow]);

    $this->auditor->shouldNotReceive('recordCreated');

    $assignment = $this->assignRole->assign($this->target, $this->role);

    expect($assignment->wasRecentlyCreated)->toBeFalse();
});

it('leaves an absent assignment alone instead of auditing a removal', function (): void {
    $connection = ($this->answering)([]);

    $this->auditor->shouldNotReceive('recordDeleted');
    $this->lockoutGuard->shouldNotReceive('assertLastEscalatedAssignmentSurvives');

    $this->assignRole->remove($this->target, $this->role);

    expect($connection->writtenStatements)->toBe([]);
});

it('asks whether the last escalated assignment survives before deleting the row it found', function (): void {
    $connection = ($this->answering)([$this->assignmentRow]);

    $seenBeforeDelete = null;

    $this->lockoutGuard->shouldReceive('assertLastEscalatedAssignmentSurvives')
        ->once()
        ->andReturnUsing(function (Role $role, RoleAssignment $assignment) use ($connection, &$seenBeforeDelete): void {
            $seenBeforeDelete = $connection->writtenStatements;
        });

    $this->auditor->shouldReceive('recordDeleted')->once();

    $this->assignRole->remove($this->target, $this->role, null, false);

    expect($seenBeforeDelete)->toBe([])
        ->and($connection->writtenSqlOf('role_assignments'))->toHaveCount(1);
});

it('re-checks the role management of an acting user who removed their own assignment', function (): void {
    ($this->answering)([$this->assignmentRow]);
    AccessContext::actAs($this->target);

    $this->lockoutGuard->shouldReceive('assertLastEscalatedAssignmentSurvives')->once();
    $this->auditor->shouldReceive('recordDeleted')->once();
    $this->lockoutGuard->shouldReceive('assertActingUserRetainsRoleManagement')->once()->with($this->target);

    $this->assignRole->remove($this->target, $this->role);
});

it('spares that re-check when the removal hits somebody else', function (): void {
    ($this->answering)([$this->assignmentRow]);
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'privilege-other-actor'));

    $this->lockoutGuard->shouldReceive('assertLastEscalatedAssignmentSurvives')->once();
    $this->auditor->shouldReceive('recordDeleted')->once();
    $this->lockoutGuard->shouldNotReceive('assertActingUserRetainsRoleManagement');

    $this->assignRole->remove($this->target, $this->role);
});

it('refuses to manage a target of another tenant without asking the database', function (): void {
    $connection = ($this->answering)([]);
    $resolver = ($this->managedResolver)(['privilege-team'], false);

    $foreign = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('foreign-target'),
        'tenant_id' => ModelStub::ulid('foreign-tenant'),
    ]);

    expect($resolver->manages($this->actor, $foreign, $this->ability))->toBeFalse()
        ->and($connection->queries)->toBe([]);
});

it('manages any target of the own tenant without asking for a shared team', function (): void {
    $connection = ($this->answering)([]);
    $resolver = ($this->managedResolver)(['privilege-team'], true);

    expect($resolver->manages($this->actor, $this->target, $this->ability))->toBeTrue()
        ->and($connection->queries)->toBe([]);
});

it('refuses a target without a single reachable team before asking the database', function (): void {
    $connection = ($this->answering)([]);
    $resolver = ($this->managedResolver)([], false);

    expect($resolver->manages($this->actor, $this->target, $this->ability))->toBeFalse()
        ->and($connection->queries)->toBe([]);
});

it('lets the membership lookup decide once a reachable team exists', function (): void {
    $resolver = ($this->managedResolver)(['privilege-team'], false);

    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => [['exists' => true]],
    );

    expect($resolver->manages($this->actor, $this->target, $this->ability))->toBeTrue()
        ->and($connection->queries)->toHaveCount(1)
        ->and($connection->queries[0]['sql'])->toContain('exists')
        ->and($connection->queries[0]['bindings'])->toContain(ModelStub::ulid('privilege-team'));
});

it('refuses the target the membership lookup does not find in a reachable team', function (): void {
    $resolver = ($this->managedResolver)(['privilege-team'], false);

    StaticQueryConnection::install(
        static fn (string $sql): array => [['exists' => false]],
    );

    expect($resolver->manages($this->actor, $this->target, $this->ability))->toBeFalse();
});
