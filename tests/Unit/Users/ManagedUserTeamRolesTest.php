<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Users\UpdateManagedUserAction;
use App\Actions\Users\UpdateUserAction;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\FakeTeamMembershipDirectory;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);

    $this->actor = AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'actor'));
    $this->target = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'target');

    $this->leadId = (string) ModelStub::ulid('team-lead');
    $this->salesId = (string) ModelStub::ulid('sales');
    $this->editorId = (string) ModelStub::ulid('editor');
    FakePresenceVerifier::holding(['roles' => [$this->leadId, $this->editorId], 'teams' => [$this->salesId]]);
    AccessContext::grant();
    GateSpy::allowing();

    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $updateUser = Mockery::mock(UpdateUserAction::class);
    $updateUser->shouldReceive('execute')->andReturnUsing(fn (User $user): User => $user);
    app()->instance(UpdateUserAction::class, $updateUser);

    $this->syncUserRoles = Mockery::mock(SyncUserRolesAction::class);
    app()->instance(SyncUserRolesAction::class, $this->syncUserRoles);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('hands the submitted team roles to the role sync and keeps the tenant-wide roles as they are', function (): void {
    $this->directory->withAssignedRoles($this->target, [$this->editorId]);
    $teamRoles = [['role_id' => $this->leadId, 'team_id' => $this->salesId]];

    $this->syncUserRoles->shouldReceive('execute')->once()
        ->withArgs(fn (User $user, array $roleIds, ?array $submitted): bool => $roleIds === [$this->editorId] && $submitted === $teamRoles);

    app(UpdateManagedUserAction::class)->execute($this->actor, $this->target, ['team_roles' => $teamRoles]);
});

it('leaves the team roles alone when none are submitted', function (): void {
    $this->syncUserRoles->shouldReceive('execute')->once()
        ->withArgs(fn (User $user, array $roleIds, ?array $submitted = null): bool => $roleIds === [] && $submitted === null);

    app(UpdateManagedUserAction::class)->execute($this->actor, $this->target, ['role_ids' => []]);
});

it('refuses the acting user to change their own team roles', function (): void {
    $this->directory->withTeamRolesOf($this->actor, []);
    $this->syncUserRoles->shouldNotReceive('execute');

    expect(fn () => app(UpdateManagedUserAction::class)->execute($this->actor, $this->actor, [
        'team_roles' => [['role_id' => $this->leadId, 'team_id' => $this->salesId]],
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('team_roles');
    });
});
