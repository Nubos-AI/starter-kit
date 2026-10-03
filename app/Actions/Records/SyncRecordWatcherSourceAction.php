<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use App\Models\RecordWatcher;
use App\Support\Watchers\WatcherAutoSubscriber;
use App\Support\Watchers\WatcherEligibility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SyncRecordWatcherSourceAction
{
    public function __construct(
        private readonly WatcherAutoSubscriber $watcherAutoSubscriber,
        private readonly WatcherEligibility $eligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(
        CustomRecord $record,
        array $data,
        string $field,
        WatcherSource $source,
        string $ineligibleMessage,
    ): void {
        $validated = Validator::make($data, [
            $field => ['present', 'array'],
            "{$field}.*" => ['string', 'ulid'],
        ])->validate();

        /** @var list<string> $targetIds */
        $targetIds = array_values(array_unique(array_map(
            static fn (mixed $id): string => (string) $id,
            $validated[$field] ?? [],
        )));

        $this->eligibility->assertMayWatch($record, $targetIds, $field, $ineligibleMessage);

        DB::transaction(function () use ($record, $targetIds, $source): void {
            RecordWatcher::query()
                ->where('record_id', $record->getKey())
                ->where('source', $source)
                ->whereNotIn('user_id', $targetIds === [] ? [''] : $targetIds)
                ->delete();

            foreach ($targetIds as $userId) {
                $this->watcherAutoSubscriber->subscribe(
                    (string) $record->getKey(),
                    $userId,
                    $source,
                    Auth::id() === null ? null : (string) Auth::id(),
                );
            }
        });
    }
}
