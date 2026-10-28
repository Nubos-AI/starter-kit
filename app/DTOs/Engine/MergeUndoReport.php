<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

readonly class MergeUndoReport
{
    /**
     * @param  list<string>  $restoredFields
     * @param  list<string>  $keptFields
     * @param  array<string, int>  $restoredTransfers
     * @param  array<string, int>  $unrecoverable
     */
    public function __construct(
        public string $mergeId,
        public string $targetId,
        public string $sourceId,
        public array $restoredFields,
        public array $keptFields,
        public array $restoredTransfers,
        public array $unrecoverable,
    ) {}
}
