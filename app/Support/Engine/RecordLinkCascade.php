<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\RecordLink;
use Illuminate\Database\Eloquent\Builder;

class RecordLinkCascade
{
    public function purge(string $objectTypeId, string $recordId): int
    {
        return $this->touching($objectTypeId, $recordId)->delete();
    }

    /**
     * @return Builder<RecordLink>
     */
    public function touching(string $objectTypeId, string $recordId): Builder
    {
        return RecordLink::query()
            ->withoutGlobalScopes()
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $outgoing): Builder => $outgoing
                    ->where('from_record_type', $objectTypeId)
                    ->where('from_record_id', $recordId))
                ->orWhere(fn (Builder $incoming): Builder => $incoming
                    ->where('to_record_type', $objectTypeId)
                    ->where('to_record_id', $recordId)));
    }
}
