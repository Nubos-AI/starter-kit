<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RecordBackfillStrategyInterface;
use App\Support\Abstracts\ConfigDrivenRegistry;
use InvalidArgumentException;

/**
 * @extends ConfigDrivenRegistry<RecordBackfillStrategyInterface>
 */
class RecordBackfillStrategyRegistry extends ConfigDrivenRegistry
{
    public function for(string $kind): RecordBackfillStrategyInterface
    {
        $strategy = $this->resolve(
            $this->entryClass($kind) ?? throw new InvalidArgumentException(
                "No record backfill strategy is registered for kind [{$kind}].",
            ),
        );

        if ($strategy->kind() !== $kind) {
            throw new InvalidArgumentException(
                "The record backfill strategy registered for kind [{$kind}] reports kind [{$strategy->kind()}].",
            );
        }

        return $strategy;
    }

    public function has(string $kind): bool
    {
        return $this->entryClass($kind) !== null;
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
