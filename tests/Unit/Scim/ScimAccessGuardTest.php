<?php

declare(strict_types=1);

use App\Enums\Scim\ScimErrorType;
use App\Enums\Users\UserStatus;
use App\Exceptions\Scim\ScimException;
use App\Models\Role;
use App\Models\ScimToken;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Scim\ScimAccessGuard;
use App\Support\Scim\ScimUserDirectory;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');

    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->permissions = AccessContext::grant('sso.manage');

    $this->creator = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('helpdesk'),
        'tenant_id' => $this->tenantId,
        'status' => UserStatus::Accepted->value,
    ]);

    $this->token = fn (?User $creator): ScimToken => ModelStub::make(ScimToken::class, [
        'id' => ModelStub::ulid('token'),
        'tenant_id' => $this->tenantId,
        'created_by_id' => $creator?->getKey(),
    ], ['creator' => $creator]);

    $this->target = fn (string $seed, bool $escalated = false): User => tap(ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenantId,
        'status' => UserStatus::Accepted->value,
    ]), static function (StaticAuthorityUser $user) use ($escalated): void {
        $user->escalated = $escalated;
    });

    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    app()->instance(PermissionSubsetGuard::class, $this->subsetGuard);

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);

    $this->users = Mockery::mock(ScimUserDirectory::class);
    app()->instance(ScimUserDirectory::class, $this->users);

    $this->scimError = static function (Closure $call): ?array {
        try {
            $call();
        } catch (ScimException $exception) {
            return [$exception->status, $exception->scimType, $exception->getMessage()];
        }

        return null;
    };

    $this->guard = fn (): ScimAccessGuard => app(ScimAccessGuard::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to steer an account whose permissions exceed those of the token creator', function (): void {
    $admin = ($this->target)('admin');
    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->with($this->creator, $admin)->andReturnFalse();

    expect(($this->scimError)(fn () => ($this->guard)()->assertMaySteer(($this->token)($this->creator), $admin)))
        ->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.beyond_token_creator')]);
});

it('lets the token steer an account within the rights of its creator', function (): void {
    $member = ($this->target)('member');
    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->with($this->creator, $member)->andReturnTrue();

    expect(fn () => ($this->guard)()->assertMaySteer(($this->token)($this->creator), $member))->not->toThrow(ScimException::class);
});

it('keeps an escalated account untouchable even for a token of a super admin', function (): void {
    $this->subsetGuard->shouldNotReceive('maySteerAccount');

    expect(($this->scimError)(fn () => ($this->guard)()->assertMaySteer(($this->token)($this->creator), ($this->target)('owner', true))))
        ->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.privileged_account')]);
});

it('refuses every account change while the token has no entitled creator', function (?string $state): void {
    $creator = match ($state) {
        null => null,
        'blocked' => ModelStub::make(User::class, ['id' => ModelStub::ulid('blocked'), 'tenant_id' => $this->tenantId, 'status' => UserStatus::Blocked->value]),
        default => ModelStub::make(User::class, ['id' => ModelStub::ulid('stranger'), 'tenant_id' => ModelStub::ulid('globex'), 'status' => UserStatus::Accepted->value]),
    };
    $this->subsetGuard->shouldNotReceive('maySteerAccount');

    expect(($this->scimError)(fn () => ($this->guard)()->assertMaySteer(($this->token)($creator), ($this->target)('member'))))
        ->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.token_without_creator')]);
})->with([
    'no creator' => [null],
    'blocked creator' => ['blocked'],
    'creator of another tenant' => ['foreign'],
]);

it('tells whether a token still belongs to an entitled creator', function (?string $state, bool $expected): void {
    $creator = match ($state) {
        null => null,
        'entitled' => $this->creator,
        'blocked' => ModelStub::make(User::class, ['id' => ModelStub::ulid('blocked'), 'tenant_id' => $this->tenantId, 'status' => UserStatus::Blocked->value]),
        default => ModelStub::make(User::class, ['id' => ModelStub::ulid('stranger'), 'tenant_id' => ModelStub::ulid('globex'), 'status' => UserStatus::Accepted->value]),
    };

    expect(($this->guard)()->hasEntitledCreator(($this->token)($creator)))->toBe($expected);
})->with([
    'entitled creator' => ['entitled', true],
    'no creator' => [null, false],
    'blocked creator' => ['blocked', false],
    'creator of another tenant' => ['foreign', false],
]);

it('treats a token as orphaned once its creator lost the sso permission', function (): void {
    AccessContext::grant('members.view');
    $this->subsetGuard->shouldNotReceive('maySteerAccount');

    expect(($this->guard)()->hasEntitledCreator(($this->token)($this->creator)))->toBeFalse()
        ->and(($this->scimError)(fn () => ($this->guard)()->assertMaySteer(($this->token)($this->creator), ($this->target)('member'))))
        ->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.token_without_creator')]);
});

it('refuses to add members to a team whose roles exceed the token creator', function (): void {
    $team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('admins'), 'tenant_id' => $this->tenantId]);
    $role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('admin-role'), 'tenant_id' => $this->tenantId]);
    $this->directory->withRoles([$role])->withTeamRoles($team, [(string) $role->getKey()]);
    $this->subsetGuard->shouldReceive('mayAssignRole')->once()->with($this->creator, $role)->andReturnFalse();
    $this->users->shouldNotReceive('findMany');

    expect(($this->scimError)(fn () => ($this->guard)()->assertMayChangeMembers(($this->token)($this->creator), $team, [ModelStub::ulid('member')], [])))
        ->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.team_beyond_token_creator')]);
});

it('refuses to change the membership of an account beyond the token creator', function (): void {
    $team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('sales'), 'tenant_id' => $this->tenantId]);
    $admin = ($this->target)('admin');
    $this->users->shouldReceive('findMany')->once()->with($this->tenantId, [ModelStub::ulid('admin')])->andReturn(new Collection([$admin]));
    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->with($this->creator, $admin)->andReturnFalse();

    expect(($this->scimError)(fn () => ($this->guard)()->assertMayChangeMembers(($this->token)($this->creator), $team, [], [ModelStub::ulid('admin')]))[2])
        ->toBe(__('i18n.backend.exceptions.scim.scim_exception.beyond_token_creator'));
});

it('lets the token move members it may steer into a team whose roles its creator holds', function (): void {
    $team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('sales'), 'tenant_id' => $this->tenantId]);
    $role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('sales-role'), 'tenant_id' => $this->tenantId]);
    $this->directory->withRoles([$role])->withTeamRoles($team, [(string) $role->getKey()]);
    $member = ($this->target)('member');
    $leaver = ($this->target)('leaver');
    $this->subsetGuard->shouldReceive('mayAssignRole')->once()->with($this->creator, $role)->andReturnTrue();
    $this->users->shouldReceive('findMany')->once()->with($this->tenantId, [ModelStub::ulid('member'), ModelStub::ulid('leaver')])->andReturn(new Collection([$member, $leaver]));
    $this->subsetGuard->shouldReceive('maySteerAccount')->twice()->andReturnTrue();

    expect(fn () => ($this->guard)()->assertMayChangeMembers(($this->token)($this->creator), $team, [ModelStub::ulid('member')], [ModelStub::ulid('leaver')]))->not->toThrow(ScimException::class);
});

it('refuses to create a team while the token creator may not create teams', function (): void {
    expect(($this->scimError)(fn () => ($this->guard)()->assertMayManageTeam(($this->token)($this->creator), 'create')))
        ->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.team_change_beyond_token_creator')]);
});

it('lets the token create a team once its creator may create teams', function (): void {
    AccessContext::grant('sso.manage', 'teams.create');

    expect(fn () => ($this->guard)()->assertMayManageTeam(($this->token)($this->creator), 'create'))->not->toThrow(ScimException::class);
});

it('refuses to rename or remove a team outside the reach of the token creator', function (string $ability): void {
    AccessContext::grant('sso.manage', 'teams.update', 'teams.delete');
    $team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('board'), 'tenant_id' => $this->tenantId]);
    $managedUsers = Mockery::mock(ManagedUserResolver::class);
    $managedUsers->shouldReceive('reachOf')->once()->with($this->creator, "teams.{$ability}")->andReturn(['tenant' => false, 'teams' => [ModelStub::ulid('sales')]]);
    app()->instance(ManagedUserResolver::class, $managedUsers);
    app()->instance(TeamMembershipDirectory::class, Mockery::mock(TeamMembershipDirectory::class));

    expect(($this->scimError)(fn () => ($this->guard)()->assertMayManageTeam(($this->token)($this->creator), $ability, $team)))
        ->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.team_change_beyond_token_creator')]);
})->with(['update', 'delete']);

it('refuses every team change while the token has no entitled creator', function (): void {
    AccessContext::grant('teams.create');

    expect(($this->scimError)(fn () => ($this->guard)()->assertMayManageTeam(($this->token)($this->creator), 'create')))
        ->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.token_without_creator')]);
});

it('names the scim refusals in german', function (): void {
    expect(__('i18n.backend.exceptions.scim.scim_exception.beyond_token_creator'))->toBe('Dieses Konto hat mehr Rechte als die Person, die den SCIM-Token erzeugt hat, und wird deshalb nicht über SCIM geändert.')
        ->and(__('i18n.backend.exceptions.scim.scim_exception.team_beyond_token_creator'))->toBe('Dieses Team vergibt Rechte, die über die Rechte der Person hinausgehen, die den SCIM-Token erzeugt hat.')
        ->and(__('i18n.backend.exceptions.scim.scim_exception.token_without_creator'))->toBe('Der SCIM-Token ist keiner berechtigten Person zugeordnet. Bitte erzeugen Sie den Token neu.')
        ->and(__('i18n.backend.exceptions.scim.scim_exception.team_change_beyond_token_creator'))->toBe('Die Person, die den SCIM-Token erzeugt hat, darf dieses Team nicht anlegen, ändern oder löschen.');
});
