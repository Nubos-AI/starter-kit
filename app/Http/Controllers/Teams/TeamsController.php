<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Authorization\SyncPermissionOverridesAction;
use App\Actions\Teams\BulkDeleteTeamsAction;
use App\Actions\Teams\CreateTeamAction;
use App\Actions\Teams\DeleteTeamAction;
use App\Actions\Teams\ReparentTeamAction;
use App\Actions\Teams\SyncTeamMembersAction;
use App\Actions\Teams\SyncTeamRolesAction;
use App\Actions\Teams\UpdateTeamAction;
use App\Enums\Authorization\PermissionEffect;
use App\Exceptions\Authorization\EscalatedTeamRoleException;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\Permission;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\TenantRoleOptions;
use App\Support\Http\IdentifierList;
use App\Support\Users\UserOptionPresenter;
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
        private readonly UpdateTeamAction $updateTeam,
        private readonly ReparentTeamAction $reparentTeam,
        private readonly DeleteTeamAction $deleteTeam,
        private readonly BulkDeleteTeamsAction $bulkDeleteTeams,
        private readonly SyncTeamMembersAction $syncTeamMembers,
        private readonly SyncTeamRolesAction $syncTeamRoles,
        private readonly SyncPermissionOverridesAction $syncPermissionOverrides,
        private readonly UserOptionPresenter $userOptions,
        private readonly TenantRoleOptions $roleOptions,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actingUser($request);
        $nodes = $this->treeNodes($actor);

        return Inertia::render('teams/Index', [
            'nodes' => $nodes,
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actingUser($request);

        return Inertia::render('teams/Form', [
            'mode' => 'create',
            'team' => null,
            'parentOptions' => $this->parentOptions($actor),
            'ownerOptions' => $this->ownerOptions($actor),
            'memberOptions' => [],
            'roleOptions' => [],
            'permissionOptions' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actingUser($request);

        $this->createTeam->execute($actor, $request->all());

        return to_route('engine.teams.index');
    }

    public function edit(Request $request, string $team): Response
    {
        $actor = $this->actingUser($request);
        $subject = $this->resolveTeam($actor, $team);

        return Inertia::render('teams/Form', [
            'mode' => 'edit',
            'team' => [
                'id' => (string) $subject->getKey(),
                'name' => $subject->name,
                'slug' => $subject->slug,
                'ownerId' => $subject->owner_id,
                'memberIds' => $this->memberIds($subject),
                'roleIds' => $this->assignedRoleIds($subject),
                'deniedPermissionIds' => $this->deniedPermissionIds($subject),
            ],
            'parentOptions' => [],
            'ownerOptions' => $this->ownerOptions($actor),
            'memberOptions' => $this->ownerOptions($actor),
            'roleOptions' => $this->roleOptions->assignable(),
            'permissionOptions' => $this->permissionOptions(),
        ]);
    }

    public function update(Request $request, string $team): RedirectResponse
    {
        $actor = $this->actingUser($request);
        $subject = $this->resolveTeam($actor, $team);

        $validated = $request->all();

        $this->updateTeam->execute($actor, $subject, $validated);

        if (array_key_exists('member_ids', $validated)) {
            $this->syncTeamMembers->syncMembersOfTeam($actor, $subject, IdentifierList::from($validated['member_ids']));
        }

        if (array_key_exists('role_ids', $validated)) {
            try {
                $this->syncTeamRoles->execute($actor, $subject, IdentifierList::from($validated['role_ids']));
            } catch (EscalatedTeamRoleException $exception) {
                throw ValidationException::withMessages(['role_ids' => $exception->getMessage()]);
            }
        }

        if (array_key_exists('denied_permission_ids', $validated)) {
            $this->syncPermissionOverrides->execute(
                $subject,
                IdentifierList::from($validated['denied_permission_ids']),
            );
        }

        return to_route('engine.teams.index');
    }

    public function destroy(Request $request, string $team): RedirectResponse
    {
        $actor = $this->actingUser($request);
        $subject = $this->resolveTeam($actor, $team);

        $this->deleteTeam->execute($actor, $subject);

        return to_route('engine.teams.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->bulkDeleteTeams->execute($this->actingUser($request), $request->all());

        return to_route('engine.teams.index');
    }

    public function reparent(Request $request, string $team): RedirectResponse
    {
        $actor = $this->actingUser($request);
        $parentTeamId = $request->input('parent_team_id');

        $this->reparentTeam->execute(
            $actor,
            $this->resolveTeam($actor, $team),
            is_string($parentTeamId) && $parentTeamId !== '' ? $parentTeamId : null,
        );

        return to_route('engine.teams.index');
    }

    private function resolveTeam(User $actor, string $teamId): Team
    {
        return Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $actor->tenant_id)
            ->whereNull('deleted_at')
            ->whereKey($teamId)
            ->firstOrFail();
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

        $this->appendBranch($childrenByParent, '', 0, $nodes, $visited);

        return $nodes;
    }

    /**
     * @param  array<string, list<Team>>  $childrenByParent
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, bool>  $visited
     */
    private function appendBranch(
        array $childrenByParent,
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

            $nodes[] = [
                'id' => $id,
                'name' => $team->name,
                'slug' => $team->slug,
                'parentTeamId' => $branch === '' ? null : $branch,
                'depth' => $depth,
                'descendantTeamIds' => array_values($team->descendant_team_ids),
                'can_delete' => !$hasChildren,
                'delete_reason' => $hasChildren ? __(DeleteTeamAction::$blockedByChildrenReason) : null,
            ];

            $this->appendBranch($childrenByParent, $id, $depth + 1, $nodes, $visited);
        }
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
     * @return list<string>
     */
    private function deniedPermissionIds(Team $team): array
    {
        return array_values(
            $team->permissionOverrides()
                ->where('effect', PermissionEffect::Deny->value)
                ->pluck('permission_id')
                ->map(fn (mixed $permissionId): string => (string) $permissionId)
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
