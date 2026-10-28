<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordTreeNode;

class AncestorChainNotifier
{
    public function __construct(
        private readonly RollupDebounceStarter $starter,
        private readonly RollupOwnerFilter $ownerFilter,
    ) {}

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

        foreach ($unique as $target) {
            if ($this->ownerFilter->ownsRollups($tenantId, $target->objectTypeId)) {
                $this->starter->startOwn($tenantId, $target->objectTypeId, $target->recordId);
            }
        }
    }
}
