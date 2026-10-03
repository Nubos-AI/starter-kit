<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordTreeNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AncestorChainNotifier
{
    public function __construct(private readonly RollupDebounceStarter $starter) {}

    /**
     * @param  list<RecordTreeNode>  $ancestors
     */
    public function notify(string $tenantId, array $ancestors): void
    {
        /** @var array<string, RecordTreeNode> $unique */
        $unique = [];

        foreach ($ancestors as $ancestor) {
            $unique[$ancestor->recordId] ??= $ancestor;
        }

        if ($unique === []) {
            return;
        }

        $targets = array_values($unique);

        DB::afterCommit(function () use ($tenantId, $targets): void {
            $startedCount = 0;

            foreach ($targets as $target) {
                try {
                    $this->starter->startOwn($tenantId, $target->objectTypeId, $target->recordId);
                } catch (Throwable $exception) {
                    Log::error('Failed to start the own roll-up recompute for an ancestor record.', [
                        'tenant_id' => $tenantId,
                        'record_id' => $target->recordId,
                        'started_count' => $startedCount,
                        'ancestor_count' => count($targets),
                    ]);

                    throw $exception;
                }

                $startedCount++;
            }
        });
    }
}
