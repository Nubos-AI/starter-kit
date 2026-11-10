<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Api\ProvisionServiceUserAction;
use App\Actions\Teams\CreateTeamAction;
use App\DTOs\Scim\ScimGroupChanges;
use App\Exceptions\Scim\ScimException;
use App\Models\ScimToken;
use App\Models\Team;
use App\Models\Tenant;
use App\Support\Scim\ScimAccessGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateScimGroupAction
{
    public function __construct(
        private readonly ProvisionServiceUserAction $serviceUser,
        private readonly CreateTeamAction $createTeam,
        private readonly SyncScimGroupMembersAction $syncMembers,
        private readonly ScimAccessGuard $accessGuard,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(Tenant $tenant, ScimToken $token, ScimGroupChanges $changes): Team
    {
        $this->accessGuard->assertMayManageTeam($token, 'create');

        $actor = $this->serviceUser->execute($tenant);

        return DB::transaction(function () use ($actor, $token, $changes): Team {
            $team = $this->createTeam->execute($actor, ['name' => (string) $changes->displayName]);

            if ($changes->changesMembers()) {
                $this->syncMembers->execute($token, $team, $changes);
            }

            return $team;
        });
    }
}
