<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncPermissionOverridesAction;
use App\Actions\Teams\CreateTeamAction;
use App\Actions\Teams\DeleteTeamAction;
use App\Actions\Teams\ReparentTeamAction;
use App\Actions\Teams\SyncTeamMembersAction;
use App\Actions\Teams\SyncTeamRolesAction;
use App\Actions\Teams\UpdateTeamAction;
use App\Actions\Teams\UpsertTeamRecordAccessRuleAction;
use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Http\Controllers\Teams\TeamAccessRulesController;
use App\Http\Controllers\Teams\TeamsController;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Models\User;
use App\Support\Authorization\RowAccess\TeamAccessRuleSource;
use App\Support\Authorization\TenantRoleOptions;
use App\Support\Teams\TeamMembershipDirectory;
use App\Support\Teams\TeamSegment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\URL;
use Inertia\Support\SessionKey;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\FakeTeamMembershipDirectory;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\Doubles\StaticTeamAccessRuleSource;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    URL::defaults([TeamSegment::key() => 'nubos']);

    $this->tenant = AccessContext::tenant('acme');
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant));
    $this->teamId = (string) ModelStub::ulid('company');

    StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "teams"') => [['id' => $this->teamId, 'tenant_id' => $this->tenant->getKey(), 'name' => 'Nubos Company', 'slug' => 'nubos-company', 'owner_id' => null, 'parent_team_id' => ModelStub::ulid('nubos'), 'descendant_team_ids' => '[]']],
            str_contains($sql, 'from "object_types"') => [['id' => ModelStub::ulid('companies'), 'tenant_id' => $this->tenant->getKey(), 'slug' => 'companies', 'name' => 'Firmen']],
            default => [],
        },
        static fn (): int => 1,
    );

    $this->roleOptions = Mockery::mock(TenantRoleOptions::class);
    $this->roleOptions->shouldReceive('assignable')->andReturn([['value' => (string) ModelStub::ulid('editor'), 'label' => 'companies-editor']]);
    app()->instance(TenantRoleOptions::class, $this->roleOptions);

    $this->updateTeam = Mockery::mock(UpdateTeamAction::class);
    $this->syncMembers = Mockery::mock(SyncTeamMembersAction::class);
    $this->syncRoles = Mockery::mock(SyncTeamRolesAction::class);
    $this->syncOverrides = Mockery::mock(SyncPermissionOverridesAction::class);
    app()->instance(UpdateTeamAction::class, $this->updateTeam);
    app()->instance(SyncTeamMembersAction::class, $this->syncMembers);
    app()->instance(SyncTeamRolesAction::class, $this->syncRoles);
    app()->instance(SyncPermissionOverridesAction::class, $this->syncOverrides);

    $this->request = function (array $input = [], string $method = 'GET'): Request {
        $request = Request::create('/probe', $method, $input);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        $request->setUserResolver(fn (): User => $this->user);
        app()->instance('request', $request);

        return $request;
    };

    $this->page = function (string $method): array {
        GateSpy::allowing('update');
        $request = ($this->request)();

        return app(TeamsController::class)->{$method}($request, $this->teamId)->toResponse($request)->original->getData()['page'];
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('guards every section page of a team with the team update permission', function (string $name): void {
    expect(RouteShape::named($name)->hasDeclaredMiddleware('permission:teams.update'))->toBeTrue();
})->with(['engine.teams.edit', 'engine.teams.members', 'engine.teams.roles', 'engine.teams.denied-permissions']);

it('renders each section of a team as its own page with only the payload of that section', function (string $method, string $component, array $keys): void {
    $page = ($this->page)($method);

    expect($page['component'])->toBe($component)
        ->and($page['props']['team']['id'])->toBe($this->teamId)
        ->and($page['props']['team']['name'])->toBe('Nubos Company')
        ->and(array_keys($page['props']))->toContain(...$keys);
})->with([
    'details' => ['edit', 'teams/edit/Details', ['ownerOptions']],
    'members' => ['members', 'teams/edit/Members', ['memberIds', 'memberOptions']],
    'roles' => ['roles', 'teams/edit/Roles', ['roleIds', 'roleOptions']],
    'denied permissions' => ['deniedPermissions', 'teams/edit/DeniedPermissions', ['deniedPermissionIds', 'permissionOptions']],
]);

it('keeps the members of one section out of the payload of another', function (): void {
    $page = ($this->page)('roles');

    expect(array_keys($page['props']))->not->toContain('memberIds', 'memberOptions', 'deniedPermissionIds', 'permissionOptions');
});

it('saves only the submitted section and returns to it with a german confirmation', function (): void {
    GateSpy::allowing('update');
    $this->updateTeam->shouldReceive('execute')->once()->withArgs(fn (User $actor, Team $team, array $input): bool => $input === ['member_ids' => [(string) $this->user->getKey()]]);
    $this->syncMembers->shouldReceive('syncMembersOfTeam')->once()->withArgs(fn (User $actor, Team $team, array $ids): bool => $ids === [(string) $this->user->getKey()]);
    $this->syncRoles->shouldNotReceive('execute');
    $this->syncOverrides->shouldNotReceive('execute');

    $request = ($this->request)(['member_ids' => [(string) $this->user->getKey()]], 'PUT');
    $request->headers->set('referer', route('engine.teams.members', ['team' => $this->teamId]));
    $response = app(TeamsController::class)->update($request, $this->teamId);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe(route('engine.teams.members', ['team' => $this->teamId]))
        ->and(session()->get(SessionKey::FLASH_DATA)['toast']['message'] ?? null)->toBe('Team gespeichert.');
});

it('refuses every team section and change outside the reach of the actor before an action runs', function (string $method, string $ability): void {
    $gate = GateSpy::allowing();
    $this->updateTeam->shouldNotReceive('execute');
    $this->syncMembers->shouldNotReceive('syncMembersOfTeam');
    $this->syncRoles->shouldNotReceive('execute');
    $this->syncOverrides->shouldNotReceive('execute');
    $delete = Mockery::mock(DeleteTeamAction::class);
    $delete->shouldNotReceive('execute');
    app()->instance(DeleteTeamAction::class, $delete);
    $reparent = Mockery::mock(ReparentTeamAction::class);
    $reparent->shouldNotReceive('execute');
    app()->instance(ReparentTeamAction::class, $reparent);

    expect(fn () => app(TeamsController::class)->{$method}(($this->request)(['name' => 'Gekapert', 'role_ids' => []], 'PUT'), $this->teamId))
        ->toThrow(AuthorizationException::class)
        ->and($gate->calls[0]['ability'])->toBe($ability)
        ->and($gate->calls[0]['arguments'][0]->getKey())->toBe($this->teamId);
})->with([
    'details page' => ['edit', 'update'],
    'members page' => ['members', 'update'],
    'roles page' => ['roles', 'update'],
    'denied permissions page' => ['deniedPermissions', 'update'],
    'update' => ['update', 'update'],
    'delete' => ['destroy', 'delete'],
    'reparent' => ['reparent', 'reparent'],
]);

it('marks a team outside the reach of the actor as not editable, movable or deletable and names the reason', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('other-team')]);
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    GateSpy::allowing('viewAny');
    $request = ($this->request)();

    $node = app(TeamsController::class)->index($request)->toResponse($request)->original->getData()['page']['props']['nodes'][0];
    $reason = __('i18n.backend.support.authorization.managed_access_guard.this_team_is_outside_your_management_reach');

    expect($node['id'])->toBe($this->teamId)
        ->and([$node['can_update'], $node['can_reparent'], $node['can_delete']])->toBe([false, false, false])
        ->and([$node['update_reason'], $node['reparent_reason'], $node['delete_reason']])->toBe([$reason, $reason, $reason]);
});

it('lets a tenant-wide holder edit, move and delete every team of the tree', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    GateSpy::allowing('viewAny');
    $request = ($this->request)();

    $node = app(TeamsController::class)->index($request)->toResponse($request)->original->getData()['page']['props']['nodes'][0];

    expect([$node['can_update'], $node['can_reparent'], $node['can_delete']])->toBe([true, true, true])
        ->and([$node['update_reason'], $node['reparent_reason'], $node['delete_reason']])->toBe([null, null, null]);
});

it('marks a team the actor belongs to as neither movable nor deletable and names the inherited access rules as the reason', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($this->user, [$this->teamId]));
    GateSpy::allowing('viewAny');
    $request = ($this->request)();

    $node = app(TeamsController::class)->index($request)->toResponse($request)->original->getData()['page']['props']['nodes'][0];

    expect([$node['can_update'], $node['can_reparent'], $node['can_delete']])->toBe([true, false, false])
        ->and($node['reparent_reason'])->toBe('Sie gehören diesem Team oder einem seiner Unterteams an. Verschieben würde die Zugriffsregeln ändern, die Sie selbst einschränken – das kann nur eine Administration.')
        ->and($node['delete_reason'])->toBe('Sie gehören diesem Team oder einem seiner Unterteams an. Löschen würde die Zugriffsregeln aufheben, die Sie selbst einschränken – das kann nur eine Administration.');
});

it('returns to the record access section after saving a rule', function (): void {
    GateSpy::allowing('create', 'manageAccessRules');
    AccessContext::grant('companies.view');
    $upsert = Mockery::mock(UpsertTeamRecordAccessRuleAction::class);
    $upsert->shouldReceive('execute')->once();
    app()->instance(UpsertTeamRecordAccessRuleAction::class, $upsert);

    $response = app(TeamAccessRulesController::class)->update(($this->request)([], 'PUT'), $this->teamId, (string) ModelStub::ulid('companies'));

    expect($response->getTargetUrl())->toBe(route('engine.teams.access-rules.index', ['team' => $this->teamId]));
});

it('continues in the sections of a newly created team instead of returning to the list', function (): void {
    $create = Mockery::mock(CreateTeamAction::class);
    $create->shouldReceive('execute')->once()->andReturn(ModelStub::make(Team::class, ['id' => $this->teamId, 'tenant_id' => $this->tenant->getKey(), 'name' => 'Nubos Company']));
    app()->instance(CreateTeamAction::class, $create);
    GateSpy::allowing('create');

    $response = app(TeamsController::class)->store(($this->request)(['name' => 'Nubos Company'], 'POST'));

    expect($response->getTargetUrl())->toBe(route('engine.teams.edit', ['team' => $this->teamId]));
});

it('marks a team whose own or inherited access rules are active as neither movable nor deletable for an actor who may not manage access rules and names the reason', function (): void {
    StaticQueryConnection::uninstall();
    StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "teams"')
            ? [['id' => $this->teamId, 'tenant_id' => $this->tenant->getKey(), 'name' => 'Nubos Company', 'slug' => 'nubos-company', 'owner_id' => null, 'parent_team_id' => null, 'ancestor_team_ids' => json_encode([ModelStub::ulid('nubos')]), 'descendant_team_ids' => '[]']]
            : [],
        static fn (): int => 1,
    );
    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    app()->instance(TeamAccessRuleSource::class, new StaticTeamAccessRuleSource(new EloquentCollection([
        ModelStub::make(TeamRecordAccessRule::class, [
            'id' => ModelStub::ulid('nubos-rule'),
            'tenant_id' => $this->tenant->getKey(),
            'team_id' => ModelStub::ulid('nubos'),
            'object_type_id' => ModelStub::ulid('companies'),
            'is_active' => true,
            'inheritance' => TeamAccessRuleInheritance::Intersect->value,
            'filter_definition' => ['combinator' => 'and', 'conditions' => [['field' => 'postal_code', 'operator' => 'startsWith', 'value' => '1']]],
        ]),
    ])));
    GateSpy::allowing('viewAny');
    $request = ($this->request)();

    $node = app(TeamsController::class)->index($request)->toResponse($request)->original->getData()['page']['props']['nodes'][0];

    expect([$node['can_update'], $node['can_reparent'], $node['can_delete']])->toBe([true, false, false])
        ->and($node['reparent_reason'])->toBe('Für ein übergeordnetes Team gelten Zugriffsregeln. Verschieben würde sie für die Mitglieder ändern – dafür benötigen Sie das Recht, Zugriffsregeln zu verwalten.')
        ->and($node['delete_reason'])->toBe('Für dieses Team oder ein übergeordnetes Team gelten Zugriffsregeln. Löschen würde sie für die Mitglieder aufheben – dafür benötigen Sie das Recht, Zugriffsregeln zu verwalten.');
});

it('keeps a team with active access rules movable and deletable for an actor who may manage access rules', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    app()->instance(TeamAccessRuleSource::class, new StaticTeamAccessRuleSource(new EloquentCollection([
        ModelStub::make(TeamRecordAccessRule::class, [
            'id' => ModelStub::ulid('company-rule'),
            'tenant_id' => $this->tenant->getKey(),
            'team_id' => $this->teamId,
            'object_type_id' => ModelStub::ulid('companies'),
            'is_active' => true,
            'inheritance' => TeamAccessRuleInheritance::Intersect->value,
            'filter_definition' => ['combinator' => 'and', 'conditions' => [['field' => 'postal_code', 'operator' => 'startsWith', 'value' => '1']]],
        ]),
    ])));
    GateSpy::allowing('viewAny');
    $request = ($this->request)();

    $node = app(TeamsController::class)->index($request)->toResponse($request)->original->getData()['page']['props']['nodes'][0];

    expect([$node['can_reparent'], $node['can_delete']])->toBe([true, true])
        ->and([$node['reparent_reason'], $node['delete_reason']])->toBe([null, null]);
});
