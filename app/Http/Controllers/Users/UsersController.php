<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Actions\Users\DeleteUserAction;
use App\Actions\Users\UpdateManagedUserAction;
use App\Enums\Authorization\PermissionEffect;
use App\Enums\Ui\ToastType;
use App\Enums\Users\Salutation;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\User;
use App\Support\Authorization\AssignableRoleOptions;
use App\Support\Authorization\PermissionOptions;
use App\Support\Http\IdentifierList;
use App\Support\Teams\TenantTeamOptions;
use App\Support\Users\ManageableUserRows;
use App\Support\Users\TenantUserResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UsersController extends Controller
{
    public function __construct(
        private readonly UpdateManagedUserAction $updateManagedUser,
        private readonly DeleteUserAction $deleteUser,
        private readonly TenantUserResolver $tenantUserResolver,
        private readonly AssignableRoleOptions $assignableRoles,
        private readonly TenantTeamOptions $assignableTeams,
        private readonly ManageableUserRows $userRows,
        private readonly PermissionOptions $permissionOptions,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $actingUser = $this->actingUser($request);

        return Inertia::render('users/Index', [
            'users' => $this->userRows->forActingUser($this->tenantUsers($actingUser), $actingUser),
            'roles' => $this->assignableRoles->forUser($request, $actingUser),
        ]);
    }

    public function edit(Request $request, string $user): InertiaResponse
    {
        $actingUser = $this->actingUser($request);
        $target = $this->resolveTenantUser($actingUser, $user, 'update');

        return Inertia::render('users/Form', [
            'user' => [
                'id' => $target->getKey(),
                'salutation' => $target->salutation->value,
                'first_name' => $target->first_name,
                'last_name' => $target->last_name,
                'email' => $target->email,
                'role_ids' => $this->assignedRoleIds($target),
                'team_ids' => $this->assignedTeamIds($target),
                'denied_permission_ids' => $this->deniedPermissionIds($target),
                'status' => $target->status->value,
                'can_update_password' => $actingUser->can('updatePassword', $target),
                'can_manage_access' => !$actingUser->is($target),
            ],
            'salutations' => Salutation::options(),
            'roles' => $this->assignableRoles->forUser($request, $actingUser, withPermissions: true),
            'teams' => $this->assignableTeams->forUser($actingUser),
            'permissions' => $this->permissionOptions->all(),
        ]);
    }

    public function update(Request $request, string $user): RedirectResponse
    {
        $actingUser = $this->actingUser($request);
        $target = $this->resolveTenantUser($actingUser, $user, 'update');

        try {
            $this->updateManagedUser->execute($actingUser, $target, $request->all());
        } catch (SelfLockoutException $exception) {
            throw ValidationException::withMessages(['role_ids' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.users.users_controller.user_saved')]);

        return to_route('engine.users.index');
    }

    public function destroy(Request $request, string $user): RedirectResponse
    {
        $actingUser = $this->actingUser($request);
        $target = $this->resolveTenantUser($actingUser, $user, 'delete');

        try {
            $this->deleteUser->execute($target);
        } catch (SelfLockoutException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.users.users_controller.user_deleted')]);

        return to_route('engine.users.index');
    }

    /**
     * @return Collection<int, User>
     */
    private function tenantUsers(User $actingUser): Collection
    {
        return User::query()
            ->where('tenant_id', $actingUser->tenant_id)
            ->where('is_service', false)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    private function resolveTenantUser(User $actingUser, string $userId, string $ability): User
    {
        return $this->tenantUserResolver->resolveAuthorized($actingUser, $userId, $ability);
    }

    /**
     * @return list<string>
     */
    private function assignedTeamIds(User $user): array
    {
        return IdentifierList::from($user->teams()->pluck('teams.id')->all());
    }

    /**
     * @return list<string>
     */
    private function deniedPermissionIds(User $user): array
    {
        return IdentifierList::from(
            $user->permissionOverrides()
                ->where('effect', PermissionEffect::Deny->value)
                ->pluck('permission_id')
                ->all(),
        );
    }

    /**
     * @return list<string>
     */
    private function assignedRoleIds(User $user): array
    {
        return IdentifierList::from($user->roleAssignments()->pluck('role_id')->all());
    }
}
