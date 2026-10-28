<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\DTOs\Outbox\OutboxDispatchOutcome;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Outbox\OutboxSubject;
use App\Enums\Outbox\OutboxTopic;
use App\Models\OutboxEvent;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class OutboxMessageDispatcher
{
    public function __construct(
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly OutboxHandlerRegistry $handlers,
    ) {}

    /**
     * @param  array<string, string|null>  $logContext
     *
     * @throws Throwable
     */
    public function dispatchPending(array $logContext = []): OutboxDispatchOutcome
    {
        $batchSize = max(1, (int) config('engine.outbox.batch_size'));
        $outcome = new OutboxDispatchOutcome(handled: 0, retried: 0, failed: 0);

        do {
            $claimed = $this->claim($batchSize);
            $outcome = $outcome->plus($this->handleClaimed($claimed, $logContext));
        } while ($claimed->count() === $batchSize);

        return $outcome;
    }

    /**
     * @param  list<string>  $lockedTenantIds
     * @return Builder<OutboxEvent>
     */
    public function claimQuery(array $lockedTenantIds, int $batchSize): Builder
    {
        return OutboxEvent::query()
            ->where('topic', '<>', OutboxTopic::RecordChanged->value)
            ->whereNull('published_at')
            ->whereNull('failed_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->when(
                $lockedTenantIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('tenant_id', $lockedTenantIds),
            )
            ->orderBy('sequence')
            ->limit($batchSize)
            ->lock('for update skip locked');
    }

    /**
     * @return Collection<int, OutboxEvent>
     *
     * @throws Throwable
     */
    private function claim(int $batchSize): Collection
    {
        return DB::transaction(function () use ($batchSize): Collection {
            $claimed = $this->claimQuery($this->maintenanceLocks->lockedTenantIds(), $batchSize)->get();

            if ($claimed->isNotEmpty()) {
                OutboxEvent::query()
                    ->whereKey($claimed->modelKeys())
                    ->increment('attempts', 1, ['available_at' => now()->addSeconds((int) config('engine.outbox.message_lease_seconds'))]);
            }

            return $claimed;
        });
    }

    /**
     * @param  Collection<int, OutboxEvent>  $claimed
     * @param  array<string, string|null>  $logContext
     *
     * @throws JsonException
     */
    private function handleClaimed(Collection $claimed, array $logContext): OutboxDispatchOutcome
    {
        $handledIds = [];
        $failedIds = [];

        /** @var array<int, list<string>> $retriedIdsByAttempt */
        $retriedIdsByAttempt = [];

        foreach ($claimed->groupBy(fn (OutboxEvent $event): string => $this->identityOf($event)) as $entries) {
            $message = $this->messageOf($entries);
            $ids = array_map('strval', $entries->modelKeys());
            $context = $this->contextOf($message, $logContext);
            $handlers = $this->handlers->handlersFor($message->topic);

            if ($handlers === []) {
                Log::warning('Outbox entry has no handler for its topic; the entry is set aside.', $context);
                $failedIds = [...$failedIds, ...$ids];

                continue;
            }

            try {
                foreach ($handlers as $handler) {
                    $handler->handle($message);
                }

                $handledIds = [...$handledIds, ...$ids];
            } catch (Throwable $exception) {
                $failureContext = [...$context, 'exception' => $exception::class, 'reason' => $exception->getMessage()];

                if ($message->attempts >= (int) config('engine.outbox.message_max_attempts')) {
                    Log::error('Outbox handler failed on its last attempt; the entry is given up.', $failureContext);
                    $failedIds = [...$failedIds, ...$ids];

                    continue;
                }

                Log::warning('Outbox handler failed; the entry is retried.', $failureContext);
                $retriedIdsByAttempt[$message->attempts] = [...($retriedIdsByAttempt[$message->attempts] ?? []), ...$ids];
            }
        }

        $this->settle($handledIds, $retriedIdsByAttempt, $failedIds);

        return new OutboxDispatchOutcome(
            handled: count($handledIds),
            retried: array_sum(array_map('count', $retriedIdsByAttempt)),
            failed: count($failedIds),
        );
    }

    /**
     * @param  list<string>  $handledIds
     * @param  array<int, list<string>>  $retriedIdsByAttempt
     * @param  list<string>  $failedIds
     */
    private function settle(array $handledIds, array $retriedIdsByAttempt, array $failedIds): void
    {
        if ($handledIds !== []) {
            OutboxEvent::query()->whereKey($handledIds)->update(['published_at' => now()]);
        }

        foreach ($retriedIdsByAttempt as $attempts => $ids) {
            OutboxEvent::query()
                ->whereKey($ids)
                ->update(['available_at' => now()->addSeconds($attempts * (int) config('engine.outbox.message_retry_seconds'))]);
        }

        if ($failedIds !== []) {
            OutboxEvent::query()->whereKey($failedIds)->update(['failed_at' => now()]);
        }
    }

    /**
     * @param  Collection<int, OutboxEvent>  $entries
     */
    private function messageOf(Collection $entries): OutboxMessage
    {
        /** @var OutboxEvent $first */
        $first = $entries->first();

        return new OutboxMessage(
            topic: $first->topic,
            tenantId: $first->tenant_id,
            subject: new OutboxSubject((string) $first->subject_type, (string) $first->subject_id),
            payload: $first->payload ?? [],
            attempts: (int) $entries->max('attempts') + 1,
        );
    }

    /**
     * @throws JsonException
     */
    private function identityOf(OutboxEvent $event): string
    {
        $payload = json_encode($event->payload ?? [], JSON_THROW_ON_ERROR);

        return "{$event->topic}|{$event->tenant_id}|{$event->subject_type}|{$event->subject_id}|{$payload}";
    }

    /**
     * @param  array<string, string|null>  $logContext
     * @return array<string, string|int|null>
     */
    private function contextOf(OutboxMessage $message, array $logContext): array
    {
        return [
            'topic' => $message->topic,
            'tenant_id' => $message->tenantId,
            'subject_type' => $message->subject->type,
            'subject_id' => $message->subject->id,
            'attempts' => $message->attempts,
            ...$logContext,
        ];
    }
}
