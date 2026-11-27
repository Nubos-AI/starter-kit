<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\Enums\Users\UserStatus;
use App\Exceptions\Scim\ScimException;
use App\Models\Role;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ScimAccessGuard
{
    public function __construct(
        private readonly PermissionSubsetGuard $subsetGuard,
        private readonly AuthorizationDirectory $directory,
        private readonly ScimUserDirectory $users,
    ) {}

    /**
     * @throws ScimException
     */
    public function assertMaySteer(ScimToken $token, User $target): void
    {
        if ($target->isEscalatedAuthority()) {
            Log::warning('Scim tried to change an owner or super admin account.', ['tenant_id' => $target->tenant_id, 'user_id' => $target->getKey()]);

            throw ScimException::privilegedAccount();
        }

        $creator = $this->creatorOf($token);

        if (!$this->subsetGuard->maySteerAccount($creator, $target)) {
            Log::warning('Scim tried to change an account beyond the rights of the token creator.', ['tenant_id' => $token->tenant_id, 'token_id' => $token->getKey(), 'creator_id' => $creator->getKey(), 'user_id' => $target->getKey()]);

            throw ScimException::beyondTokenCreator();
        }
    }

    /**
     * @throws ScimException
     */
    public function assertMayProvision(ScimToken $token, ?SsoConnection $connection, string $email): void
    {
        $creator = $this->creatorOf($token);

        $this->assertClaimedEmail($token, $connection, $email);

        $roleId = $connection?->default_role_id;

        if ($roleId === null) {
            return;
        }

        $role = $this->firstRoleBeyond($creator, $this->directory->rolesByIds([$roleId]));

        if (!$role instanceof Role) {
            return;
        }

        Log::warning('Scim tried to provision an account with a default role beyond the rights of the token creator.', ['tenant_id' => $token->tenant_id, 'token_id' => $token->getKey(), 'creator_id' => $creator->getKey(), 'role_id' => $role->getKey()]);

        throw ScimException::defaultRoleBeyondTokenCreator();
    }

    /**
     * @throws ScimException
     */
    public function assertClaimedEmail(ScimToken $token, ?SsoConnection $connection, string $email): void
    {
        if ($connection instanceof SsoConnection && $connection->claimsEmail($email)) {
            return;
        }

        Log::warning('Scim tried to assign an email address outside the proven single sign-on domains.', ['tenant_id' => $token->tenant_id, 'token_id' => $token->getKey()]);

        throw ScimException::emailOutsideSsoDomains();
    }

    /**
     * @param  list<string>  $addedUserIds
     * @param  list<string>  $removedUserIds
     *
     * @throws ScimException
     */
    public function assertMayChangeMembers(ScimToken $token, Team $team, array $addedUserIds, array $removedUserIds): void
    {
        $changedUserIds = array_values(array_unique([...$addedUserIds, ...$removedUserIds]));

        if ($changedUserIds === []) {
            return;
        }

        if ($addedUserIds !== []) {
            $this->assertMayGrantTeamRoles($token, $team);
        }

        foreach ($this->users->findMany($token->tenant_id, $changedUserIds) as $user) {
            $this->assertMaySteer($token, $user);
        }
    }

    /**
     * @throws ScimException
     */
    public function assertMayManageTeam(ScimToken $token, string $ability, ?Team $team = null): void
    {
        $creator = $this->creatorOf($token);

        if (Gate::forUser($creator)->allows($ability, $team ?? Team::class)) {
            return;
        }

        Log::warning('Scim tried to change a team beyond the rights of the token creator.', ['tenant_id' => $token->tenant_id, 'token_id' => $token->getKey(), 'creator_id' => $creator->getKey(), 'team_id' => $team?->getKey(), 'ability' => $ability]);

        throw ScimException::teamChangeBeyondTokenCreator();
    }

    public function hasEntitledCreator(ScimToken $token): bool
    {
        return $this->entitledCreator($token) instanceof User;
    }

    public function entitledCreator(ScimToken $token): ?User
    {
        $creator = $token->creator;

        if (!$creator instanceof User) {
            return null;
        }

        $isEntitled = $creator->tenant_id === $token->tenant_id
            && $creator->status === UserStatus::Accepted
            && $creator->hasPermission('sso.manage');

        return $isEntitled ? $creator : null;
    }

    /**
     * @throws ScimException
     */
    private function assertMayGrantTeamRoles(ScimToken $token, Team $team): void
    {
        $creator = $this->creatorOf($token);
        $role = $this->firstRoleBeyond($creator, $this->directory->rolesAssignedToTeams([(string) $team->getKey()]));

        if (!$role instanceof Role) {
            return;
        }

        Log::warning('Scim tried to add members to a team whose roles exceed the rights of the token creator.', ['tenant_id' => $token->tenant_id, 'token_id' => $token->getKey(), 'creator_id' => $creator->getKey(), 'team_id' => $team->getKey(), 'role_id' => $role->getKey()]);

        throw ScimException::teamBeyondTokenCreator();
    }

    /**
     * @throws ScimException
     */
    private function creatorOf(ScimToken $token): User
    {
        $creator = $this->entitledCreator($token);

        if ($creator instanceof User) {
            return $creator;
        }

        Log::warning('A scim token without an entitled creator tried to change accounts.', ['tenant_id' => $token->tenant_id, 'token_id' => $token->getKey()]);

        throw ScimException::tokenWithoutCreator();
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    private function firstRoleBeyond(User $creator, Collection $roles): ?Role
    {
        return $roles->first(fn (Role $role): bool => !$this->subsetGuard->mayAssignRole($creator, $role));
    }
}
