<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Authorization\SyncPermissionOverridesAction;
use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Teams\SyncTeamMembersAction;
use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\ManagedAccessGuard;
use App\Support\Authorization\RoleInputRules;
use App\Support\Http\IdentifierList;
use App\Support\Sso\SsoEnforcementGate;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        private readonly RequestEmailChangeAction $requestEmailChange,
        private readonly SyncUserRolesAction $syncUserRoles,
        private readonly SyncTeamMembersAction $syncTeamMembers,
        private readonly SyncPermissionOverridesAction $syncPermissionOverrides,
        private readonly RoleInputRules $rules,
        private readonly ManagedAccessGuard $accessGuard,
        private readonly AuthorizationDirectory $directory,
        private readonly TeamMembershipDirectory $memberships,
        private readonly SsoEnforcementGate $ssoEnforcement,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(User $actingUser, User $target, array $data): User
    {
        Validator::make($data, [...$this->rules->roleIds('sometimes'), ...$this->rules->teamRoles('sometimes')])->validate();

        $rolesSubmitted = array_key_exists('role_ids', $data);
        $roleIds = $rolesSubmitted ? IdentifierList::from($data['role_ids']) : [];
        $teamRoles = array_key_exists('team_roles', $data) ? $this->teamRoles($data['team_roles']) : null;

        $this->assertOwnAccessUnchanged($actingUser, $target, $data);

        if ($rolesSubmitted) {
            $this->assertMayChangeRoles($actingUser, $target, $roleIds);
        }

        $this->assertMayChangeEmail($actingUser, $target, $data);

        $emailAwaitingConfirmation = $this->emailAwaitingConfirmation($target, $data);

        return DB::transaction(function () use ($actingUser, $target, $data, $roleIds, $rolesSubmitted, $teamRoles, $emailAwaitingConfirmation): User {
            $updated = $this->updateUser->execute($target, Arr::only($data, $this->profileKeys));

            if ($emailAwaitingConfirmation !== null) {
                $this->requestEmailChange->execute($updated, $emailAwaitingConfirmation);
            }

            if ($rolesSubmitted || $teamRoles !== null) {
                $this->syncUserRoles->execute(
                    $updated,
                    $rolesSubmitted ? $roleIds : $this->directory->directRoleIdsOf($updated),
                    $teamRoles,
                );
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
            'role_ids' => $this->directory->directRoleIdsOf($target),
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

        if (array_key_exists('team_roles', $data)) {
            $this->accessGuard->assertOwnAccessUnchanged(
                $actingUser,
                $target,
                $this->teamRoleKeys($this->directory->teamRolesOf($target)),
                $this->teamRoleKeys($this->teamRoles($data['team_roles'])),
                'team_roles',
            );
        }
    }

    /**
     * @return list<array{role_id: string, team_id: string}>
     */
    private function teamRoles(mixed $submitted): array
    {
        return array_values(array_map(
            static fn (mixed $entry): array => [
                'role_id' => (string) data_get($entry, 'role_id'),
                'team_id' => (string) data_get($entry, 'team_id'),
            ],
            is_array($submitted) ? $submitted : [],
        ));
    }

    /**
     * @param  list<array{role_id: string, team_id: string}>  $teamRoles
     * @return list<string>
     */
    private function teamRoleKeys(array $teamRoles): array
    {
        return array_map(
            static fn (array $entry): string => "{$entry['role_id']}|{$entry['team_id']}",
            $teamRoles,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emailAwaitingConfirmation(User $target, array $data): ?string
    {
        return $target->status === UserStatus::Invited ? null : $this->changedEmail($target, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function changedEmail(User $target, array $data): ?string
    {
        $email = $data['email'] ?? null;

        if (!is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));

        return $email === Str::lower($target->email) ? null : $email;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function assertMayChangeEmail(User $actingUser, User $target, array $data): void
    {
        $email = $this->changedEmail($target, $data);

        if ($email === null) {
            return;
        }

        Gate::forUser($actingUser)->authorize('updatePassword', $target);

        if (!$this->ssoEnforcement->releasesEnforcedEmail($target, $email)) {
            return;
        }

        Log::info('A managed email change was refused because it would leave the enforced single sign-on domains.', ['tenant_id' => $target->tenant_id, 'user_id' => $target->getKey(), 'acting_user_id' => $actingUser->getKey()]);

        throw ValidationException::withMessages(['email' => __('i18n.backend.actions.users.update_managed_user_action.email_must_stay_within_the_sso_domains')]);
    }

    /**
     * @param  list<string>  $roleIds
     *
     * @throws AuthorizationException
     */
    private function assertMayChangeRoles(User $actingUser, User $target, array $roleIds): void
    {
        $current = $this->directory->directRoleIdsOf($target);

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
