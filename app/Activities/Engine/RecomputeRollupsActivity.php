<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\RecomputeRollupsActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Engine\RollupDebounceStarter;
use App\Support\Engine\RollupRecomputer;
use Throwable;

class RecomputeRollupsActivity implements RecomputeRollupsActivityInterface
{
    public function __construct(
        private readonly RollupDebounceStarter $starter,
        private readonly RollupRecomputer $recomputer,
    ) {}

    /**
     * @throws Throwable
     */
    public function recomputeRollups(RecordChangeBatch $changes): int
    {
        $this->starter->collect(function () use ($changes): void {
            /** @var array<string, bool> $relevance */
            $relevance = [];

            foreach ($changes->changes as $change) {
                $sortedKeys = $change->changedFieldKeys;
                sort($sortedKeys);
                $keyList = implode(',', $sortedKeys);

                $relevanceKey = "{$change->tenantId}|{$change->objectTypeId}|{$keyList}";
                $relevance[$relevanceKey] ??= $this->recomputer->isCascadeRelevant(
                    $change->tenantId,
                    $change->objectTypeId,
                    $change->changedFieldKeys,
                );

                if (!$relevance[$relevanceKey]) {
                    continue;
                }

                $this->starter->start(
                    $change->tenantId,
                    $change->objectTypeId,
                    $change->recordId,
                    $change->changedFieldKeys,
                );
            }
        });

        return $changes->count();
    }
}
