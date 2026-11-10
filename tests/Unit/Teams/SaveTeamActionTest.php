<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncPermissionOverridesAction;
use App\Actions\Teams\SaveTeamAction;
use App\Actions\Teams\SyncTeamMembersAction;
use App\Actions\Teams\SyncTeamRolesAction;
use App\Exceptions\Authorization\EscalatedTeamRoleException;
use App\Models\Team;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'team-admin'));
    $this->team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('sales'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Vertrieb', 'slug' => 'vertrieb']);

    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $this->syncMembers = Mockery::mock(SyncTeamMembersAction::class);
    $this->syncRoles = Mockery::mock(SyncTeamRolesAction::class);
    $this->syncOverrides = Mockery::mock(SyncPermissionOverridesAction::class);
    app()->instance(SyncTeamMembersAction::class, $this->syncMembers);
    app()->instance(SyncTeamRolesAction::class, $this->syncRoles);
    app()->instance(SyncPermissionOverridesAction::class, $this->syncOverrides);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('rolls the renamed team and its new members back when a later section of the same request fails', function (): void {
    $this->syncMembers->shouldReceive('syncMembersOfTeam')->once()->andReturnUsing(function (): void {
        $this->connection->statement('insert into "team_user" ("team_id", "user_id") values (?, ?)', ['probe', 'probe']);
    });
    $this->syncRoles->shouldReceive('execute')->once()->andThrow(EscalatedTeamRoleException::forRole('owner'));
    $this->syncOverrides->shouldNotReceive('execute');

    expect(fn () => app(SaveTeamAction::class)->execute($this->actor, $this->team, [
        'name' => 'Vertrieb Nord',
        'member_ids' => [(string) $this->actor->getKey()],
        'role_ids' => [(string) ModelStub::ulid('owner')],
        'denied_permission_ids' => [],
    ]))->toThrow(EscalatedTeamRoleException::class)
        ->and($this->connection->writtenStatements)->toHaveCount(2)
        ->and($this->connection->rolledBackStatements)->toBe($this->connection->writtenStatements)
        ->and($this->connection->openedTransactions)->toBe(1);
});

it('runs only the submitted sections', function (): void {
    $this->syncMembers->shouldReceive('syncMembersOfTeam')->once()->withArgs(fn ($actor, Team $team, array $ids): bool => $ids === [(string) $this->actor->getKey()]);
    $this->syncRoles->shouldNotReceive('execute');
    $this->syncOverrides->shouldNotReceive('execute');

    app(SaveTeamAction::class)->execute($this->actor, $this->team, ['member_ids' => [(string) $this->actor->getKey()]]);
});
