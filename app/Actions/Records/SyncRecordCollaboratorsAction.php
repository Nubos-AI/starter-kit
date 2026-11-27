<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use Throwable;

class SyncRecordCollaboratorsAction
{
    private string $field = 'collaborator_ids';

    private string $ineligibleMessage = 'i18n.backend.actions.records.sync_record_collaborators_action.only_users_of_this_tenant_who_may_view_the';

    public function __construct(private readonly SyncRecordWatcherSourceAction $syncWatcherSource) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(CustomRecord $record, array $data): void
    {
        $this->syncWatcherSource->execute(
            $record,
            $data,
            $this->field,
            WatcherSource::Collaborator,
            __($this->ineligibleMessage),
        );
    }
}
