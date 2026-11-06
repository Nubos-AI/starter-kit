<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge\Abstracts;

use App\Contracts\Engine\MergeStrategyHandler;
use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\Engine\MergeValueOrigin;

abstract class SideMergeStrategyHandler implements MergeStrategyHandler
{
    public function resolve(MergeValueContext $context): MergeValueOutcome
    {
        $origin = $this->winningSide($context);

        return new MergeValueOutcome(
            $origin === MergeValueOrigin::Source ? $context->sourceValue() : $context->targetValue(),
            $origin,
        );
    }

    abstract protected function winningSide(MergeValueContext $context): MergeValueOrigin;
}
