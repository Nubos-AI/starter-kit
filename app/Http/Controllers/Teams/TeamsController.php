<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\BulkDeleteTeamsAction;
use App\Actions\Teams\CreateTeamAction;
use App\Actions\Teams\DeleteTeamAction;
use App\Actions\Teams\ReparentTeamAction;
use App\Actions\Teams\SaveTeamAction;
use App\Enums\Ui\ToastType;
use App\Exceptions\Authorization\EscalatedTeamRoleException;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\Permission;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\RowAccess\TeamAccessRuleCoverage;
use App\Support\Authorization\TenantRoleOptions;
use App\Support\Http\IdentifierList;
use App\Support\Teams\TeamMembershipDirectory;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamsController extends Controller
{
    public function __construct(
        private readonly CreateTeamAction $createTeam,
        private readonly SaveTeamAction $saveTeam,
        private readonly ReparentTeamAction $reparentTeam,
        private readonly DeleteTeamAction $deleteTeam,
        private readonly BulkDeleteTeamsAction $bulkDeleteTeams,
        private readonly UserOptionPresenter $userOptions,
        private readonly TenantRoleOptions $roleOptions,
        private readonly ManagedUserResolver $managedUsers,
        private readonly TeamMembershipDirectory $memberships,
        private readonly AuthorizationDirectory $directory,
        private readonly TeamAccessRuleCoverage $ruleCoverage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Team::class);

        $actor = $this->actingUser($request);
        $nodes = $this->treeNodes($actor);

        return Inertia::render('teams/Index', [
            'nodes' => $nodes,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Team::class);

        $actor = $this->actingUser($request);

        return Inertia::render('teams/edit/Details', [
            'mode' => 'create',
            'team' => null,
            'parentOptions' => $this->parentOptions($actor),
            'ownerOptions' => $this->ownerOptions($actor),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Team::class);

        $actor = $this->actingUser($request);

        $team = $this->createTeam->execute($actor, $request->all());

        return to_route('engine.teams.edit', ['team' => $team->getKey()]);
    }

    public function edit(Request $request, string $team): Response
    {
        $actor = $this->actingUser($request);
        $subject = $this->authorizedTeam($actor, $team, 'update');

        return Inertia::render('teams/edit/Details', [
            'mode' => 'edit',
            'team' => [
                ...$this->teamSummary($subject),
                'slug' => $subject->slug,
                'ownerId' => $subject->owner_id,
            ],
            'parentOptions' => [],
            'ownerOptions' => $this->ownerOptions($actor),
        ]);
    }

    public function members(Request $request, string $team): Response
    {
        $actor = $this->actingUser($request);
        $subject = $this->authorizedTeam($actor, $team, 'update');

        return Inertia::render('teams/edit/Members', [
            'team' => $this->teamSummary($subject),
            'memberIds' => $this->memberIds($subject),
            'memberOptions' => $this->ownerOptions($actor),
        ]);
    }

    public function roles(Request $request, string $team): Response
    {
        $actor = $this->actingUser($request);
        $subject = $this->authorizedTeam($actor, $team, 'update');

        return Inertia::render('teams/edit/Roles', [
            'team' => $this->teamSummary($subject),
            'roleIds' => $this->assignedRoleIds($subject),
            'roleOptions' => $this->roleOptions->assignable(),
        ]);
    }

    public function deniedPermissions(Request $request, string $team): Response
    {
        $actor = $this->actingUser($request);
        $subject = $this->authorizedTeam($actor, $team, 'update');

        return Inertia::render('teams/edit/DeniedPermissions', [
            'team' => $this->teamSummary($subject),
            'deniedPermissionIds' => IdentifierList::from($this->directory->deniedPermissionIdsOf($subject)),
            'permissionOptions' => $this->permissionOptions(),
        ]);
    }

    public function update(Request $request, string $team): RedirectResponse
    {
        $actor = $this->actingUser($request);
        $subject = $this->authorizedTeam($actor, $team, 'update');

        try {
            $this->saveTeam->execute($actor, $subject, $request->all());
        } catch (EscalatedTeamRoleException $exception) {
            throw ValidationException::withMessages(['role_ids' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.teams.teams_controller.saved')]);

        return back();
    }

    public function destroy(Request $request, string $team): RedirectResponse
    {
        $actor = $this->actingUser($request);
        $subject = $this->authorizedTeam($actor, $team, 'delete');

        $this->deleteTeam->execute($actor, $subject);

        return to_route('engine.teams.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('deleteAny', Team::class);

        $this->bulkDeleteTeams->execute($this->actingUser($request), $request->all());

        return to_route('engine.teams.index');
    }

    public function reparent(Request $request, string $team): RedirectResponse
    {
        $actor = $this->actingUser($request);
        $subject = $this->authorizedTeam($actor, $team, 'reparent');
        $parentTeamId = $request->input('parent_team_id');

        $this->reparentTeam->execute(
            $actor,
            $subject,
            is_string($parentTeamId) && $parentTeamId !== '' ? $parentTeamId : null,
        );

        return to_route('engine.teams.index');
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizedTeam(User $actor, string $teamId, string $ability): Team
    {
        $team = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $actor->tenant_id)
            ->whereNull('deleted_at')
            ->whereKey($teamId)
            ->firstOrFail();

        $this->authorize($ability, $team);

        return $team;
    }

    /**
     * @return array{id: string, name: string}
     */
    private function teamSummary(Team $team): array
    {
        return [
            'id' => (string) $team->getKey(),
            'name' => $team->name,
        ];
    }

    /**
     * @return Collection<int, Team>
     */
    private function livingTeams(User $actor): Collection
    {
        return Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $actor->tenant_id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function treeNodes(User $actor): array
    {
        $teams = $this->livingTeams($actor);
        $livingIds = [];

        foreach ($teams as $team) {
            $livingIds[(string) $team->getKey()] = true;
        }

        /** @var array<string, list<Team>> $childrenByParent */
        $childrenByParent = [];

        foreach ($teams as $team) {
            $parentId = $team->parent_team_id;
            $branch = $parentId !== null && isset($livingIds[$parentId]) ? $parentId : '';
            $childrenByParent[$branch][] = $team;
        }

        $nodes = [];
        $visited = [];
        $reaches = [
            'update' => $this->managedUsers->reachOf($actor, 'teams.update'),
            'reparent' => $this->managedUsers->reachOf($actor, 'teams.reparent'),
            'delete' => $this->managedUsers->reachOf($actor, 'teams.delete'),
            'manage' => $this->managedUsers->reachOf($actor, 'teams.manage'),
        ];

        $ownTeamIds = $actor->isEscalatedAuthority() ? [] : $this->memberships->teamIdsOf($actor);
        $chainIds = IdentifierList::from($teams->flatMap(fn (Team $team): array => $this->ruleCoverage->chainOf($team))->all());
        $governedTeamIds = array_fill_keys($this->ruleCoverage->governedTeamIds($chainIds), true);

        $this->appendBranch($childrenByParent, $reaches, $ownTeamIds, $governedTeamIds, '', 0, $nodes, $visited);

        return $nodes;
    }

    /**
     * @param  array<string, list<Team>>  $childrenByParent
     * @param  array<string, array{tenant: bool, teams: list<string>}>  $reaches
     * @param  list<string>  $ownTeamIds
     * @param  array<string, bool>  $governedTeamIds
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, bool>  $visited
     */
    private function appendBranch(
        array $childrenByParent,
        array $reaches,
        array $ownTeamIds,
        array $governedTeamIds,
        string $branch,
        int $depth,
        array &$nodes,
        array &$visited,
    ): void {
        foreach ($childrenByParent[$branch] ?? [] as $team) {
            $id = (string) $team->getKey();

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            $hasChildren = ($childrenByParent[$id] ?? []) !== [];
            $mayUpdate = $this->reaches($reaches['update'], $id);
            $reachesForMove = $this->reaches($reaches['reparent'], $id);
            $mayManageRules = $this->reaches($reaches['manage'], $id);
            $isSelfRestricted = $reachesForMove && $this->memberships->isWithinSubtree($team, $ownTeamIds);
            $isMoveRuleBound = $reachesForMove && !$isSelfRestricted && !$mayManageRules
                && $this->isGoverned($governedTeamIds, $this->ruleCoverage->ancestorsOf($team));
            $mayReparent = $reachesForMove && !$isSelfRestricted && !$isMoveRuleBound;
            $reachesForDelete = $this->reaches($reaches['delete'], $id);
            $isDeleteSelfRestricted = $reachesForDelete && $this->memberships->isWithinSubtree($team, $ownTeamIds);
            $isDeleteRuleBound = $reachesForDelete && !$isDeleteSelfRestricted && !$mayManageRules
                && $this->isGoverned($governedTeamIds, $this->ruleCoverage->chainOf($team));
            $mayDelete = $reachesForDelete && !$isDeleteSelfRestricted && !$isDeleteRuleBound;

            $nodes[] = [
                'id' => $id,
                'name' => $team->name,
                'slug' => $team->slug,
                'parentTeamId' => $branch === '' ? null : $branch,
                'depth' => $depth,
                'descendantTeamIds' => array_values($team->descendant_team_ids),
                'can_update' => $mayUpdate,
                'update_reason' => $this->outsideReachReason($reaches['update'], $mayUpdate),
                'can_reparent' => $mayReparent,
                'reparent_reason' => match (true) {
                    $isSelfRestricted => __('i18n.backend.http.controllers.teams.teams_controller.moving_would_change_rules_restricting_yourself'),
                    $isMoveRuleBound => __('i18n.backend.http.controllers.teams.teams_controller.moving_would_change_rules_you_may_not_manage'),
                    default => $this->outsideReachReason($reaches['reparent'], $mayReparent),
                },
                'can_delete' => !$hasChildren && $mayDelete,
                'delete_reason' => match (true) {
                    $hasChildren => __(DeleteTeamAction::$blockedByChildrenReason),
                    $isDeleteSelfRestricted => __('i18n.backend.http.controllers.teams.teams_controller.deleting_would_lift_rules_restricting_yourself'),
                    $isDeleteRuleBound => __('i18n.backend.http.controllers.teams.teams_controller.deleting_would_lift_rules_you_may_not_manage'),
                    default => $this->outsideReachReason($reaches['delete'], $mayDelete),
                },
            ];

            $this->appendBranch($childrenByParent, $reaches, $ownTeamIds, $governedTeamIds, $id, $depth + 1, $nodes, $visited);
        }
    }

    /**
     * @param  array<string, bool>  $governedTeamIds
     * @param  list<string>  $teamIds
     */
    private function isGoverned(array $governedTeamIds, array $teamIds): bool
    {
        return array_intersect_key($governedTeamIds, array_flip($teamIds)) !== [];
    }

    /**
     * @param  array{tenant: bool, teams: list<string>}  $reach
     */
    private function reaches(array $reach, string $teamId): bool
    {
        return $reach['tenant'] || in_array($teamId, $reach['teams'], true);
    }

    /**
     * @param  array{tenant: bool, teams: list<string>}  $reach
     */
    private function outsideReachReason(array $reach, bool $mayAct): ?string
    {
        if ($mayAct || $reach['teams'] === []) {
            return null;
        }

        return __('i18n.backend.support.authorization.managed_access_guard.this_team_is_outside_your_management_reach');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function parentOptions(User $actor): array
    {
        return $this->livingTeams($actor)
            ->map(fn (Team $team): array => [
                'value' => (string) $team->getKey(),
                'label' => $team->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function assignedRoleIds(Team $team): array
    {
        return array_values(
            $team->roleAssignments()
                ->pluck('role_id')
                ->map(fn (mixed $roleId): string => (string) $roleId)
                ->unique()
                ->all(),
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function permissionOptions(): array
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $permission): array => [
                'value' => (string) $permission->getKey(),
                'label' => $permission->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function memberIds(Team $team): array
    {
        return array_values(
            $team->users()
                ->pluck('users.id')
                ->map(fn (mixed $userId): string => (string) $userId)
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string, description: string, avatar: array{name: string}}>
     */
    private function ownerOptions(User $actor): array
    {
        return $this->userOptions->presentMany(
            User::query()
                ->where('tenant_id', $actor->tenant_id)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
        );
    }
}
