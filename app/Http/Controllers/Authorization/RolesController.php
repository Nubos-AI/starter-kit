<?php

declare(strict_types=1);

namespace App\Http\Controllers\Authorization;

use App\Actions\Authorization\BulkDeleteRolesAction;
use App\Actions\Authorization\CreateRoleAction;
use App\Actions\Authorization\DeleteRoleAction;
use App\Actions\Authorization\SyncRolePermissionsAction;
use App\Actions\Authorization\UpdateRoleAction;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\Authorization\RoleScope;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\RoleResource;
use App\Models\ObjectType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\FieldPermissionMatrix;
use App\Support\Authorization\RoleDeletionGuard;
use App\Support\Authorization\RoleInputRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RolesController extends Controller
{
    public function __construct(
        private readonly CreateRoleAction $createRole,
        private readonly UpdateRoleAction $updateRole,
        private readonly DeleteRoleAction $deleteRole,
        private readonly BulkDeleteRolesAction $bulkDeleteRoles,
        private readonly SyncRolePermissionsAction $syncRolePermissions,
        private readonly RoleInputRules $rules,
        private readonly RoleDeletionGuard $deletionGuard,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Role::class);

        $user = $this->actingUser($request);

        $userCounts = $this->deletionGuard->userCountsByRole();
        $teamCounts = $this->deletionGuard->teamCountsByRole();

        return Inertia::render('roles/Index', [
            'roles' => Role::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): array => $this->indexRow(
                    $user,
                    $role,
                    $userCounts[$role->getKey()] ?? 0,
                    $teamCounts[$role->getKey()] ?? 0,
                ))
                ->all(),
            'scopes' => $this->enumOptions(RoleScope::cases()),
            'authorities' => $this->enumOptions(RoleAuthority::cases()),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $this->authorize('create', Role::class);

        return Inertia::render('roles/Form', $this->formPayload($request, 'create', null));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $role = $this->createRole->execute($this->actingUser($request), $request->all());

        return to_route('engine.roles.edit', ['role' => $role->getKey()]);
    }

    public function edit(Request $request, Role $role): InertiaResponse
    {
        $this->authorize('update', $role);

        return Inertia::render('roles/Form', $this->formPayload($request, 'edit', $role));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $this->updateRole->execute($this->actingUser($request), $role, $request->all());

        return to_route('engine.roles.edit', ['role' => $role->getKey()]);
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $this->deleteRole->execute($this->actingUser($request), $role);

        return to_route('engine.roles.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('deleteAny', Role::class);

        $this->bulkDeleteRoles->execute($this->actingUser($request), $request->all());

        return to_route('engine.roles.index');
    }

    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $this->syncRolePermissions->execute($role, $request->all());

        return response()->json([
            'assigned' => $role->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function indexRow(User $user, Role $role, int $userCount, int $teamCount): array
    {
        $mayDelete = $user->can('delete', $role);
        $blockReason = $mayDelete ? $this->deletionGuard->blockReason($userCount, $teamCount) : null;

        return array_merge(RoleResource::make($role)->resolve(), [
            'can_update' => $user->can('update', $role),
            'can_delete' => $mayDelete && $blockReason === null,
            'delete_reason' => $blockReason,
            'user_count' => $userCount,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(Request $request, string $mode, ?Role $role): array
    {
        $user = $this->actingUser($request);

        return [
            'mode' => $mode,
            'role' => $role === null ? null : RoleResource::make($role)->resolve(),
            'scopes' => $this->enumOptions($this->rules->assignableScopes($user)),
            'authorities' => $this->enumOptions($this->rules->assignableAuthorities($user)),
            'permissions' => [
                'assigned' => $role === null ? [] : $role->permissions()->pluck('permissions.id')->all(),
                'tabs' => $this->permissionTabs(),
            ],
            'fieldPermissions' => $role === null ? [] : FieldPermissionMatrix::forRole($role),
            'isEscalated' => $role?->authority !== null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function permissionTabs(): array
    {
        $tabConfig = (array) config('permissions.tabs', []);
        $groupConfig = (array) config('permissions.groups', []);
        uasort($groupConfig, fn (array $left, array $right): int => ($left['order'] ?? 0) <=> ($right['order'] ?? 0));

        $permissions = Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get(['id', 'name', 'group']);

        $itemsByGroup = [];

        foreach ($permissions as $permission) {
            $itemsByGroup[(string) $permission->group][] = [
                'id' => $permission->id,
                'name' => $permission->name,
            ];
        }

        $objectTypeNames = ObjectType::query()->pluck('name', 'slug');

        $tabs = [];

        foreach ($tabConfig as $tabKey => $tab) {
            $tabKey = (string) $tabKey;
            $tab = (array) $tab;
            $type = (string) ($tab['type'] ?? 'direct');

            if ($type === 'dropdown' && $tabKey === 'records') {
                $groups = [];

                foreach ($itemsByGroup as $group => $items) {
                    if ($group === '' || array_key_exists($group, $groupConfig)) {
                        continue;
                    }

                    $name = $objectTypeNames->get($group);

                    $groups[] = [
                        'key' => $group,
                        'label' => is_string($name) ? $name : Str::headline($group),
                        'permissions' => $items,
                    ];
                }

                usort($groups, fn (array $left, array $right): int => strcmp($left['label'], $right['label']));
            } else {
                $groups = [];

                foreach ($groupConfig as $groupKey => $meta) {
                    $meta = (array) $meta;

                    if ((string) ($meta['tab'] ?? '') !== $tabKey) {
                        continue;
                    }

                    $groups[] = [
                        'key' => (string) $groupKey,
                        'label' => __((string) ($meta['label'] ?? $groupKey)),
                        'permissions' => $itemsByGroup[(string) $groupKey] ?? [],
                    ];
                }
            }

            $tabs[] = [
                'key' => $tabKey,
                'label' => __((string) ($tab['label'] ?? $tabKey)),
                'type' => $type,
                'groups' => $groups,
            ];
        }

        return $tabs;
    }

    /**
     * @param  array<int, RoleScope|RoleAuthority>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(
            fn (RoleScope|RoleAuthority $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
