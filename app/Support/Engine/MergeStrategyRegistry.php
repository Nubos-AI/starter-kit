<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\MergeStrategyHandler;
use App\Enums\Engine\MergeFieldStrategy;
use App\Exceptions\Engine\UnknownMergeStrategyException;
use App\Support\Abstracts\ConfigDrivenRegistry;

/**
 * @extends ConfigDrivenRegistry<MergeStrategyHandler>
 */
class MergeStrategyRegistry extends ConfigDrivenRegistry
{
    /**
     * @throws UnknownMergeStrategyException
     */
    public function handlerFor(MergeFieldStrategy $strategy): MergeStrategyHandler
    {
        return $this->resolve(
            $this->entryClass($strategy->value) ?? throw new UnknownMergeStrategyException($strategy->value),
        );
    }

    protected function configKey(): string
    {
        return 'engine.merge.strategies';
    }

    /**
     * @return class-string<MergeStrategyHandler>
     */
    protected function contract(): string
    {
        return MergeStrategyHandler::class;
    }
}
