<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Api\ProvisionServiceUserAction;
use App\Actions\Teams\DeleteTeamAction;
use App\Exceptions\Scim\ScimException;
use App\Models\ScimToken;
use App\Models\Team;
use App\Models\Tenant;
use App\Support\Scim\ScimAccessGuard;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteScimGroupAction
{
    public function __construct(
        private readonly ProvisionServiceUserAction $serviceUser,
        private readonly DeleteTeamAction $deleteTeam,
        private readonly TeamMembershipDirectory $memberships,
        private readonly ScimAccessGuard $accessGuard,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(Tenant $tenant, ScimToken $token, Team $team): void
    {
        $this->accessGuard->assertMayManageTeam($token, 'delete', $team);
        $this->accessGuard->assertMayChangeMembers($token, $team, [], $this->memberships->memberIdsOf($team));

        $this->deleteTeam->execute($this->serviceUser->execute($tenant), $team);

        Log::info('A team was removed through scim.', ['tenant_id' => $tenant->getKey(), 'team_id' => $team->getKey()]);
    }
}
