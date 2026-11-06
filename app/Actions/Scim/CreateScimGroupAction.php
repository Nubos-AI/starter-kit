<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Api\ProvisionServiceUserAction;
use App\Actions\Teams\CreateTeamAction;
use App\DTOs\Scim\ScimGroupChanges;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateScimGroupAction
{
    public function __construct(
        private readonly ProvisionServiceUserAction $serviceUser,
        private readonly CreateTeamAction $createTeam,
        private readonly SyncScimGroupMembersAction $syncMembers,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(Tenant $tenant, ScimGroupChanges $changes): Team
    {
        $actor = $this->serviceUser->execute($tenant);

        return DB::transaction(function () use ($actor, $changes): Team {
            $team = $this->createTeam->execute($actor, ['name' => (string) $changes->displayName]);

            if ($changes->changesMembers()) {
                $this->syncMembers->execute($team, $changes);
            }

            return $team;
        });
    }
}
