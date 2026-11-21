<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Api\ProvisionServiceUserAction;
use App\Actions\Teams\UpdateTeamAction;
use App\DTOs\Scim\ScimGroupChanges;
use App\Exceptions\Scim\ScimException;
use App\Models\ScimToken;
use App\Models\Team;
use App\Models\Tenant;
use App\Support\Scim\ScimAccessGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

class UpdateScimGroupAction
{
    public function __construct(
        private readonly ProvisionServiceUserAction $serviceUser,
        private readonly UpdateTeamAction $updateTeam,
        private readonly SyncScimGroupMembersAction $syncMembers,
        private readonly ScimAccessGuard $accessGuard,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(Tenant $tenant, ScimToken $token, Team $team, ScimGroupChanges $changes): Team
    {
        return DB::transaction(function () use ($tenant, $token, $team, $changes): Team {
            if ($changes->displayName !== null && $changes->displayName !== $team->name) {
                $this->accessGuard->assertMayManageTeam($token, 'update', $team);
                $this->updateTeam->execute($this->serviceUser->execute($tenant), $team, ['name' => $changes->displayName]);
            }

            if ($changes->changesMembers()) {
                $this->syncMembers->execute($token, $team, $changes);
            }

            return $team;
        });
    }
}
