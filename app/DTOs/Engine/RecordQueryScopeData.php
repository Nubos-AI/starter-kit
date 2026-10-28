<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\DTOs\Reports\ReportDrillDownData;
use App\Models\ObjectType;
use App\Models\User;

readonly class RecordQueryScopeData
{
    /**
     * @param  array<string, mixed>  $filterModel
     * @param  array<array-key, mixed>  $sortModel
     */
    public function __construct(
        public ObjectType $objectType,
        public User $viewer,
        public ?string $segmentId = null,
        public ?ReportDrillDownData $drillDown = null,
        public array $filterModel = [],
        public array $sortModel = [],
        public bool $hierarchy = false,
        public bool $allowTreeOrder = true,
        public ?string $search = null,
    ) {}
}
