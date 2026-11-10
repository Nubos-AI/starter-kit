<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Authorization\SyncPermissionOverridesAction;
use App\Exceptions\Authorization\EscalatedTeamRoleException;
use App\Models\Team;
use App\Models\User;
use App\Support\Http\IdentifierList;
use Illuminate\Support\Facades\DB;
use Throwable;

class SaveTeamAction
{
    public function __construct(
        private readonly UpdateTeamAction $updateTeam,
        private readonly SyncTeamMembersAction $syncTeamMembers,
        private readonly SyncTeamRolesAction $syncTeamRoles,
        private readonly SyncPermissionOverridesAction $syncPermissionOverrides,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws EscalatedTeamRoleException
     * @throws Throwable
     */
    public function execute(User $actor, Team $team, array $input): Team
    {
        return DB::transaction(function () use ($actor, $team, $input): Team {
            $this->updateTeam->execute($actor, $team, $input);

            if (array_key_exists('member_ids', $input)) {
                $this->syncTeamMembers->syncMembersOfTeam($actor, $team, IdentifierList::from($input['member_ids']));
            }

            if (array_key_exists('role_ids', $input)) {
                $this->syncTeamRoles->execute($actor, $team, IdentifierList::from($input['role_ids']));
            }

            if (array_key_exists('denied_permission_ids', $input)) {
                $this->syncPermissionOverrides->execute($team, IdentifierList::from($input['denied_permission_ids']));
            }

            return $team;
        });
    }
}
