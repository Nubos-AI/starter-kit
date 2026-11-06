<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Api\ProvisionServiceUserAction;
use App\Actions\Teams\DeleteTeamAction;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteScimGroupAction
{
    public function __construct(
        private readonly ProvisionServiceUserAction $serviceUser,
        private readonly DeleteTeamAction $deleteTeam,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(Tenant $tenant, Team $team): void
    {
        $this->deleteTeam->execute($this->serviceUser->execute($tenant), $team);

        Log::info('A team was removed through scim.', ['tenant_id' => $tenant->getKey(), 'team_id' => $team->getKey()]);
    }
}
