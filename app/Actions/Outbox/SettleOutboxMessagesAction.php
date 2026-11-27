<?php

declare(strict_types=1);

namespace App\Actions\Outbox;

use App\DTOs\Outbox\OutboxDispatchOutcome;
use App\DTOs\Outbox\OutboxSettlementData;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;

class SettleOutboxMessagesAction
{
    public function __construct(private readonly Repository $config) {}

    public function execute(OutboxSettlementData $settlement): OutboxDispatchOutcome
    {
        $maxAttempts = (int) $this->config->get('engine.outbox.message_max_attempts');
        $failedIds = [];

        /** @var array<int, list<string>> $retriedIdsByAttempt */
        $retriedIdsByAttempt = [];

        foreach ($settlement->failedDeliveries as $delivery) {
            if ($delivery['attempts'] >= $maxAttempts) {
                Log::error('Outbox handler failed on its last attempt; the entry is given up.', ['entry_ids' => $delivery['entry_ids'], 'attempts' => $delivery['attempts']]);
                $failedIds = [...$failedIds, ...$delivery['entry_ids']];

                continue;
            }

            $retriedIdsByAttempt[$delivery['attempts']] = [...($retriedIdsByAttempt[$delivery['attempts']] ?? []), ...$delivery['entry_ids']];
        }

        if ($settlement->handledIds !== []) {
            OutboxEvent::query()->whereKey($settlement->handledIds)->update(['published_at' => now()]);
        }

        foreach ($retriedIdsByAttempt as $attempts => $ids) {
            OutboxEvent::query()
                ->whereKey($ids)
                ->update(['available_at' => now()->addSeconds($attempts * (int) $this->config->get('engine.outbox.message_retry_seconds'))]);
        }

        if ($failedIds !== []) {
            OutboxEvent::query()->whereKey($failedIds)->update(['failed_at' => now()]);
        }

        if ($settlement->releasedIds !== []) {
            OutboxEvent::query()
                ->whereKey($settlement->releasedIds)
                ->decrement('attempts', 1, ['available_at' => now()]);

            Log::info('Outbox entries were handed back unhandled because the lease of their batch ran short.', ['released_count' => count($settlement->releasedIds)]);
        }

        return new OutboxDispatchOutcome(
            handled: count($settlement->handledIds),
            retried: array_sum(array_map('count', $retriedIdsByAttempt)),
            failed: count($failedIds),
        );
    }
}
