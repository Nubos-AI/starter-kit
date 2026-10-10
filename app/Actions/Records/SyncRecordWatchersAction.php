<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use Throwable;

class SyncRecordWatchersAction
{
    private const string FIELD = 'user_ids';

    private const string INELIGIBLE_MESSAGE = 'i18n.backend.actions.records.sync_record_watchers_action.only_users_of_this_tenant_who_may_view_the';

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
            self::FIELD,
            WatcherSource::Manual,
            __(self::INELIGIBLE_MESSAGE),
        );
    }
}
