<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Enums\Audit\ActorType;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

class TimelineQuery
{
    /**
     * @param  list<string>  $sourceKeys
     * @return CursorPaginator<int, TimelineEntry>
     */
    public function paginate(
        CustomRecord $record,
        array $sourceKeys = [],
        ?CarbonImmutable $occurredFrom = null,
        ?CarbonImmutable $occurredTo = null,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        ?string $cursor = null,
        int $perPage = 20,
        string $cursorName = 'timelineCursor',
    ): CursorPaginator {
        $actorValue = $actorType?->value;

        return TimelineEntry::query()
            ->where('tenant_id', $record->tenant_id)
            ->where('record_id', $record->getKey())
            ->when($sourceKeys !== [], fn (Builder $query): Builder => $query->whereIn('source_key', $sourceKeys))
            ->when($occurredFrom !== null, fn (Builder $query): Builder => $query->where('occurred_at', '>=', $occurredFrom))
            ->when($occurredTo !== null, fn (Builder $query): Builder => $query->where('occurred_at', '<=', $occurredTo))
            ->when($actorValue !== null, fn (Builder $query): Builder => $query->where('actor_type', $actorValue))
            ->when($actorId !== null, fn (Builder $query): Builder => $query->where('actor_id', $actorId))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], $cursorName, $this->cursorOrPageOne($cursor));
    }

    private function cursorOrPageOne(?string $cursor): Cursor|string
    {
        if ($cursor === null) {
            return '';
        }

        $decoded = Cursor::fromEncoded($cursor);

        if (!$decoded instanceof Cursor) {
            return '';
        }

        $parameters = $decoded->toArray();

        return array_key_exists('occurred_at', $parameters) && array_key_exists('id', $parameters)
            ? $decoded
            : '';
    }
}
