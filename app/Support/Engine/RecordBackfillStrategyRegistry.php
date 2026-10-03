<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RecordBackfillStrategyInterface;
use App\Enums\Engine\RecordBackfillKind;
use App\Support\Abstracts\ConfigDrivenRegistry;
use InvalidArgumentException;

/**
 * @extends ConfigDrivenRegistry<RecordBackfillStrategyInterface>
 */
class RecordBackfillStrategyRegistry extends ConfigDrivenRegistry
{
    public function for(RecordBackfillKind $kind): RecordBackfillStrategyInterface
    {
        $strategy = $this->resolve(
            $this->entryClass($kind->value) ?? throw new InvalidArgumentException(
                "No record backfill strategy is registered for kind [{$kind->value}].",
            ),
        );

        if ($strategy->kind() !== $kind) {
            throw new InvalidArgumentException(
                "The record backfill strategy registered for kind [{$kind->value}] reports kind [{$strategy->kind()->value}].",
            );
        }

        return $strategy;
    }

    protected function configKey(): string
    {
        return 'engine.backfill.strategies';
    }

    /**
     * @return class-string<RecordBackfillStrategyInterface>
     */
    protected function contract(): string
    {
        return RecordBackfillStrategyInterface::class;
    }
}
