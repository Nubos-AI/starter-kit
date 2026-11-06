<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Authorization\SyncPermissionOverridesAction;
use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Teams\SyncTeamMembersAction;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\ManagedAccessGuard;
use App\Support\Authorization\RoleInputRules;
use App\Support\Http\IdentifierList;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateManagedUserAction
{
    /** @var list<string> */
    private array $profileKeys = ['salutation', 'first_name', 'last_name', 'email'];

    public function __construct(
        private readonly UpdateUserAction $updateUser,
        private readonly SyncUserRolesAction $syncUserRoles,
        private readonly SyncTeamMembersAction $syncTeamMembers,
        private readonly SyncPermissionOverridesAction $syncPermissionOverrides,
        private readonly RoleInputRules $rules,
        private readonly ManagedAccessGuard $accessGuard,
        private readonly AuthorizationDirectory $directory,
        private readonly TeamMembershipDirectory $memberships,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(User $actingUser, User $target, array $data): User
    {
        Validator::make($data, $this->rules->roleIds('sometimes'))->validate();

        $rolesSubmitted = array_key_exists('role_ids', $data);
        $roleIds = $rolesSubmitted ? IdentifierList::from($data['role_ids']) : [];

        $this->assertOwnAccessUnchanged($actingUser, $target, $data);

        if ($rolesSubmitted) {
            $this->assertMayChangeRoles($actingUser, $target, $roleIds);
        }

        $this->assertMayChangeEmail($actingUser, $target, $data);

        return DB::transaction(function () use ($actingUser, $target, $data, $roleIds, $rolesSubmitted): User {
            $updated = $this->updateUser->execute($target, Arr::only($data, $this->profileKeys));

            if ($rolesSubmitted) {
                $this->syncUserRoles->execute($updated, $roleIds);
            }

            if (array_key_exists('team_ids', $data)) {
                $this->syncTeamMembers->syncTeamsOfUser(
                    $actingUser,
                    $updated,
                    IdentifierList::from($data['team_ids']),
                    'members.update',
                );
            }

            if (array_key_exists('denied_permission_ids', $data)) {
                $this->syncPermissionOverrides->execute(
                    $updated,
                    IdentifierList::from($data['denied_permission_ids']),
                );
            }

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertOwnAccessUnchanged(User $actingUser, User $target, array $data): void
    {
        if (!$actingUser->is($target)) {
            return;
        }

        $current = [
            'role_ids' => $this->directory->roleIdsAssignedTo($target),
            'team_ids' => $this->memberships->teamIdsOf($target),
            'denied_permission_ids' => $this->directory->deniedPermissionIdsOf($target),
        ];

        foreach ($current as $field => $held) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $this->accessGuard->assertOwnAccessUnchanged(
                $actingUser,
                $target,
                $held,
                IdentifierList::from($data[$field]),
                $field,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     */
    private function assertMayChangeEmail(User $actingUser, User $target, array $data): void
    {
        $email = $data['email'] ?? null;

        if (!is_string($email) || Str::lower(trim($email)) === Str::lower($target->email)) {
            return;
        }

        Gate::forUser($actingUser)->authorize('updatePassword', $target);
    }

    /**
     * @param  list<string>  $roleIds
     *
     * @throws AuthorizationException
     */
    private function assertMayChangeRoles(User $actingUser, User $target, array $roleIds): void
    {
        $current = $this->directory->roleIdsAssignedTo($target);

        $touched = IdentifierList::from([
            ...array_diff($roleIds, $current),
            ...array_diff($current, $roleIds),
        ]);

        if ($touched === []) {
            return;
        }

        foreach ($this->directory->rolesByIds($touched) as $role) {
            Gate::forUser($actingUser)->authorize('assign', $role);
        }
    }
}
