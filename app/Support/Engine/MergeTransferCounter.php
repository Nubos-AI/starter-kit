<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\MergeTransferCategory;
use App\Models\Attachment;
use App\Models\AuditEntry;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\RecordNote;
use App\Models\RecordWatcher;
use App\Models\ReminderTask;
use App\Models\TimelineEntry;
use Illuminate\Database\Eloquent\Builder;

class MergeTransferCounter
{
    public function __construct(private readonly MergeTransferCategories $categories) {}

    public function count(string $category, CustomRecord $record): int
    {
        return $this->query($category, (string) $record->getKey())?->count() ?? 0;
    }

    /**
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>|null
     */
    public function query(string $category, string $recordId): ?Builder
    {
        return match (MergeTransferCategory::tryFrom($category)) {
            MergeTransferCategory::Links => RecordLink::query()
                ->withoutGlobalScopes()
                ->where(function (Builder $query) use ($recordId): void {
                    $query->where('from_record_id', $recordId)->orWhere('to_record_id', $recordId);
                }),
            MergeTransferCategory::Attachments => Attachment::query()
                ->withoutGlobalScopes()
                ->where('record_id', $recordId),
            MergeTransferCategory::Notes => RecordNote::query()
                ->withoutGlobalScopes()
                ->where('record_id', $recordId),
            MergeTransferCategory::Watchers => RecordWatcher::query()
                ->withoutGlobalScopes()
                ->where('record_id', $recordId),
            MergeTransferCategory::Reminders => ReminderTask::query()
                ->withoutGlobalScopes()
                ->where('record_id', $recordId),
            MergeTransferCategory::Timeline => TimelineEntry::query()
                ->withoutGlobalScopes()
                ->where('record_id', $recordId),
            MergeTransferCategory::Audit => AuditEntry::query()
                ->withoutGlobalScopes()
                ->where('auditable_id', $recordId),
            null => $this->moduleQuery($category, $recordId),
        };
    }

    /** @return Builder<covariant \Illuminate\Database\Eloquent\Model>|null */
    private function moduleQuery(string $category, string $recordId): ?Builder
    {
        $model = $this->categories->moduleModel($category);

        return $model === null ? null : $model::query()->withoutGlobalScopes()->where('record_id', $recordId);
    }
}
