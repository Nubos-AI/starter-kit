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
use Illuminate\Support\Facades\Log;

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
            $this->releaseExpiredBatches($lockedTenantIds);
            $batchSize = max(1, min(200, (int) config('engine.outbox.batch_size')));
            $candidates = $this->isolatingRetries($this->claimQuery($lockedTenantIds, $batchSize)->get());

            try {
                $claimed = $this->withinPayloadLimit($candidates, $batchId);
            } catch (OutboxPayloadTooLargeException $exception) {
                OutboxEvent::query()->whereKey($exception->eventId)->update(['failed_at' => now()]);

                Log::error('A record change exceeds the relay payload limit and was set aside.', $exception->context());

                $claimed = $candidates->take(0);
            }

            if ($claimed->isNotEmpty()) {
                OutboxEvent::query()
                    ->whereKey($claimed->modelKeys())
                    ->increment('attempts', 1, [
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
            ->whereNull('failed_at')
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
     */
    private function releaseExpiredBatches(array $lockedTenantIds): void
    {
        $expired = OutboxEvent::query()
            ->where('topic', OutboxTopic::RecordChanged->value)
            ->whereNull('published_at')
            ->whereNull('failed_at')
            ->whereNotNull('relay_batch_id')
            ->where('available_at', '<=', now())
            ->when(
                $lockedTenantIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('tenant_id', $lockedTenantIds),
            )
            ->orderBy('sequence')
            ->lock('for update skip locked')
            ->get();

        if ($expired->isEmpty()) {
            return;
        }

        $maxAttempts = max(1, (int) config('engine.outbox.record_max_attempts'));

        [$exhausted, $retryable] = $expired->partition(fn (OutboxEvent $event): bool => $event->attempts >= $maxAttempts);

        if ($exhausted->isNotEmpty()) {
            OutboxEvent::query()->whereKey($exhausted->modelKeys())->update(['failed_at' => now()]);

            Log::error('Record changes failed on their last relay attempt and were set aside.', [
                'event_ids' => array_values(array_map('strval', $exhausted->modelKeys())),
                'tenant_ids' => $exhausted->pluck('tenant_id')->unique()->values()->all(),
                'relay_batch_ids' => $exhausted->pluck('relay_batch_id')->unique()->values()->all(),
                'max_attempts' => $maxAttempts,
            ]);
        }

        if ($retryable->isNotEmpty()) {
            OutboxEvent::query()->whereKey($retryable->modelKeys())->update([
                'relay_batch_id' => null,
                'available_at' => null,
            ]);
        }
    }

    /**
     * @param  Collection<int, OutboxEvent>  $candidates
     * @return Collection<int, OutboxEvent>
     */
    private function isolatingRetries(Collection $candidates): Collection
    {
        $first = $candidates->first();

        if ($first instanceof OutboxEvent && $first->attempts > 0) {
            return $candidates->take(1);
        }

        return $candidates->takeWhile(fn (OutboxEvent $event): bool => $event->attempts === 0);
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
