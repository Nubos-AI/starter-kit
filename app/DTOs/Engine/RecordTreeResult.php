<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

readonly class RecordTreeResult
{
    /**
     * @param  list<RecordTreeNode>  $nodes
     */
    public function __construct(
        public array $nodes,
        public bool $isTruncated,
        public bool $isCycleDetected,
    ) {}
}
