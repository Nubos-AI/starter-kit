<?php

declare(strict_types=1);

namespace App\DTOs\Tenancy;

readonly class PurgeReport
{
    /**
     * @param  array<string, int>  $deletedRows
     * @param  list<string>  $skippedTables
     */
    public function __construct(
        public array $deletedRows,
        public int $deletedFiles,
        public int $failedFiles,
        public array $skippedTables,
    ) {}

    public function total(): int
    {
        return array_sum($this->deletedRows);
    }
}
