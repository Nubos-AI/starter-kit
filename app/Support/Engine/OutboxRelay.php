<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordChange;
use App\DTOs\Engine\RecordChangeBatch;
use App\Enums\Outbox\OutboxTopic;
use App\Exceptions\Outbox\OutboxClaimLostException;
use App\Exceptions\Outbox\OutboxPayloadTooLargeException;
use App\Models\OutboxEvent;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OutboxRelay
{
    public function __construct(private readonly MaintenanceLockRegistry $maintenanceLocks) {}

    public function claim(string $batchId): RecordChangeBatch
    {
        return DB::transaction(function () use ($batchId): RecordChangeBatch {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["outbox-relay:{$batchId}"]);

            $existing = OutboxEvent::query()->where('relay_batch_id', $batchId)->orderBy('sequence')->lockForUpdate()->get();

            if ($existing->isNotEmpty()) {
                $this->renew($batchId, $existing->count());

                return $this->batchOf($existing, $batchId);
            }

            $lockedTenantIds = $this->maintenanceLocks->lockedTenantIds();
            $recovered = $this->recoverExpiredBatch($lockedTenantIds);
            $batchSize = max(1, min(200, (int) config('engine.outbox.batch_size')));
            $claimed = $recovered ?? $this->withinPayloadLimit($this->claimQuery($lockedTenantIds, $batchSize)->get(), $batchId);

            if ($claimed->isNotEmpty()) {
                OutboxEvent::query()
                    ->whereKey($claimed->modelKeys())
                    ->update([
                        'relay_batch_id' => $batchId,
                        'available_at' => $this->leaseUntil(),
                    ]);
            }

            return $this->batchOf($claimed, $batchId);
        });
    }

    public function acknowledge(string $batchId, int $expectedCount): void
    {
        DB::transaction(function () use ($batchId, $expectedCount): void {
            $events = OutboxEvent::query()->where('relay_batch_id', $batchId)->lockForUpdate()->get();

            if ($expectedCount < 1 || $events->count() !== $expectedCount) {
                throw new OutboxClaimLostException;
            }

            OutboxEvent::query()
                ->where('relay_batch_id', $batchId)
                ->where('topic', OutboxTopic::RecordChanged->value)
                ->whereNull('published_at')
                ->update(['published_at' => now()]);
        });
    }

    public function renew(string $batchId, int $expectedCount): bool
    {
        return DB::transaction(function () use ($batchId, $expectedCount): bool {
            $events = OutboxEvent::query()->where('relay_batch_id', $batchId)->whereNull('published_at')->lockForUpdate()->get();

            if ($expectedCount < 1 || $events->count() !== $expectedCount) {
                return false;
            }

            OutboxEvent::query()->whereKey($events->modelKeys())->update(['available_at' => $this->leaseUntil()]);

            return true;
        });
    }

    /**
     * @param  Collection<int, OutboxEvent>  $events
     * @return Collection<int, OutboxEvent>
     */
    public function withinPayloadLimit(Collection $events, string $batchId): Collection
    {
        $claimed = $events->take(0);
        $limit = max(1024, min(524288, (int) config('engine.outbox.record_payload_bytes')));
        $bytes = strlen(json_encode(['relay_batch_id' => $batchId, 'changes' => []], JSON_THROW_ON_ERROR));

        foreach ($events as $event) {
            $eventBytes = strlen(json_encode($this->changeOf($event)->toArray(), JSON_THROW_ON_ERROR)) + 1;

            if ($bytes + $eventBytes > $limit) {
                if ($claimed->isEmpty()) {
                    throw new OutboxPayloadTooLargeException((string) $event->getKey(), $eventBytes, $limit);
                }

                break;
            }

            $claimed->push($event);
            $bytes += $eventBytes;
        }

        return $claimed;
    }

    /**
     * @param  list<string>  $lockedTenantIds
     * @return Builder<OutboxEvent>
     */
    public function claimQuery(array $lockedTenantIds, int $batchSize): Builder
    {
        return OutboxEvent::query()
            ->where('topic', OutboxTopic::RecordChanged->value)
            ->whereNull('published_at')
            ->whereNull('relay_batch_id')
            ->where(fn (Builder $query): Builder => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->when(
                $lockedTenantIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('tenant_id', $lockedTenantIds),
            )
            ->orderBy('sequence')
            ->limit($batchSize)
            ->lock('for update skip locked');
    }

    /**
     * @param  list<string>  $lockedTenantIds
     * @return Collection<int, OutboxEvent>|null
     */
    private function recoverExpiredBatch(array $lockedTenantIds): ?Collection
    {
        $expired = OutboxEvent::query()
            ->where('topic', OutboxTopic::RecordChanged->value)
            ->whereNull('published_at')
            ->whereNotNull('relay_batch_id')
            ->where('available_at', '<=', now());

        if ($lockedTenantIds !== []) {
            $expired->whereNotIn('relay_batch_id', OutboxEvent::query()
                ->select('relay_batch_id')
                ->whereNotNull('relay_batch_id')
                ->whereIn('tenant_id', $lockedTenantIds));
        }

        $batchId = $expired->orderBy('sequence')->value('relay_batch_id');

        if (!is_string($batchId)) {
            return null;
        }

        $events = OutboxEvent::query()->where('relay_batch_id', $batchId)
            ->whereNull('published_at')->where('available_at', '<=', now())
            ->orderBy('sequence')->lock('for update skip locked')->get();
        $count = OutboxEvent::query()->where('relay_batch_id', $batchId)->count();

        return $events->count() === $count && $count > 0 ? $events : null;
    }

    /** @param Collection<int, OutboxEvent> $events */
    private function batchOf(Collection $events, string $batchId): RecordChangeBatch
    {
        return new RecordChangeBatch(array_values($events->map(fn (OutboxEvent $event): RecordChange => $this->changeOf($event))->all()), $batchId);
    }

    private function changeOf(OutboxEvent $event): RecordChange
    {
        return new RecordChange(
            $event->tenant_id,
            (string) $event->object_type_id,
            (string) $event->record_id,
            (int) $event->version,
            $event->sequence,
            array_values($event->changed_field_keys ?? []),
            $event->triggered_by_automation_id,
            $event->root_run_id,
        );
    }

    private function leaseUntil(): CarbonInterface
    {
        $duration = max(
            (int) config('engine.outbox.record_lease_seconds'),
            2 * (int) config('record-processing.activity_timeouts.schedule_to_close'),
        );

        return now()->addSeconds(max(1, $duration));
    }
}
