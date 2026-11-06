<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\DTOs\Scim\ScimGroupChanges;
use App\Exceptions\Scim\ScimException;
use App\Models\Team;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Tenancy\TenantUserIdResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncScimGroupMembersAction
{
    public function __construct(
        private readonly TenantUserIdResolver $tenantUserIds,
        private readonly AdminArtifactAuditor $auditor,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(Team $team, ScimGroupChanges $changes): void
    {
        $replace = $changes->replaceMembers === null ? null : $this->knownMembers($team, $changes->replaceMembers);
        $add = $this->knownMembers($team, $changes->addMembers);
        $remove = $changes->removeMembers;

        DB::transaction(function () use ($team, $replace, $add, $remove): void {
            if ($replace !== null) {
                $team->users()->sync($replace);
            }

            if ($add !== []) {
                $team->users()->syncWithoutDetaching($add);
            }

            if ($remove !== []) {
                $team->users()->detach($remove);
            }

            $this->auditor->recordEvent($team, 'scim.group_members_changed', [
                'replaced' => $replace === null ? null : count($replace),
                'added' => count($add),
                'removed' => count($remove),
            ], $team->tenant_id);
        });

        Log::info('The members of a team were changed through scim.', ['tenant_id' => $team->tenant_id, 'team_id' => $team->getKey()]);
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function knownMembers(Team $team, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $known = $this->tenantUserIds->resolve($team->tenant_id, $userIds);

        if (count($known) !== count($userIds)) {
            throw ScimException::invalidValue(__('i18n.backend.actions.scim.sync_scim_group_members_action.unknown_member'));
        }

        return $known;
    }
}
