<?php

declare(strict_types=1);

namespace App\Actions\Outbox;

use App\DTOs\Outbox\OutboxClaim;
use App\DTOs\Outbox\OutboxDelivery;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Outbox\OutboxSubject;
use App\Enums\Outbox\OutboxTopic;
use App\Models\OutboxEvent;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Outbox\OutboxHandlerRegistry;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class ClaimOutboxMessagesAction
{
    public function __construct(
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly OutboxHandlerRegistry $handlers,
        private readonly Repository $config,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(): OutboxClaim
    {
        $batchSize = max(1, (int) $this->config->get('engine.outbox.batch_size'));
        $leaseDeadline = now()->addSeconds(max(
            1,
            (int) $this->config->get('engine.outbox.message_lease_seconds') - (int) $this->config->get('engine.outbox.message_lease_margin_seconds'),
        ));

        $claimed = $this->claim($batchSize);
        $deliveries = [];
        $setAsideIds = [];

        foreach ($claimed->groupBy(fn (OutboxEvent $event): string => $this->identityOf($event)) as $entries) {
            $ids = array_values(array_map('strval', $entries->modelKeys()));
            $message = $this->messageOf($entries);

            if ($this->handlers->handlersFor($message->topic) === []) {
                Log::warning('Outbox entry has no handler for its topic; the entry is set aside.', $this->contextOf($message));
                $setAsideIds = [...$setAsideIds, ...$ids];

                continue;
            }

            $deliveries[] = new OutboxDelivery($ids, $message, $this->availableHandlerClasses($message));
        }

        if ($setAsideIds !== []) {
            OutboxEvent::query()->whereKey($setAsideIds)->update(['failed_at' => now()]);
        }

        return new OutboxClaim($deliveries, $leaseDeadline->getTimestamp(), $claimed->count() === $batchSize, count($setAsideIds));
    }

    /**
     * @param  list<string>  $lockedTenantIds
     * @return Builder<OutboxEvent>
     */
    private function claimQuery(array $lockedTenantIds, int $batchSize): Builder
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
                    ->increment('attempts', 1, ['available_at' => now()->addSeconds((int) $this->config->get('engine.outbox.message_lease_seconds'))]);
            }

            return $claimed;
        });
    }

    /**
     * @return list<string>
     */
    private function availableHandlerClasses(OutboxMessage $message): array
    {
        $all = $this->handlers->handlersFor($message->topic);
        $available = $this->handlers->handlersFor($message->topic, $message->tenantId);
        $skipped = array_values(array_filter($all, static fn (object $handler): bool => !in_array($handler, $available, true)));

        if ($skipped !== []) {
            Log::info('Outbox handlers were skipped because their module is not available for the tenant of the entry.', [
                ...$this->contextOf($message),
                'skipped_handlers' => array_map(static fn (object $handler): string => $handler::class, $skipped),
            ]);
        }

        return array_map(static fn (object $handler): string => $handler::class, $available);
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
     * @return array<string, string|int>
     */
    private function contextOf(OutboxMessage $message): array
    {
        return [
            'topic' => $message->topic,
            'tenant_id' => $message->tenantId,
            'subject_type' => $message->subject->type,
            'subject_id' => $message->subject->id,
            'attempts' => $message->attempts,
        ];
    }
}
