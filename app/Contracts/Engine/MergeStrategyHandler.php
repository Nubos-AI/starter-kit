<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\Engine\MergeFieldStrategy;

interface MergeStrategyHandler
{
    public function strategy(): MergeFieldStrategy;

    public function resolve(MergeValueContext $context): MergeValueOutcome;
}
