<?php

declare(strict_types=1);

namespace App\Support\Routing;

use App\Contracts\Routing\RoutingStrategyHandler;
use App\Enums\Routing\RoutingStrategy;
use App\Exceptions\Routing\UnknownRoutingStrategyException;
use App\Support\Abstracts\ConfigDrivenRegistry;

/**
 * @extends ConfigDrivenRegistry<RoutingStrategyHandler>
 */
class RoutingStrategyRegistry extends ConfigDrivenRegistry
{
    public function hasHandler(RoutingStrategy $strategy): bool
    {
        return $this->entryClass($strategy->value) !== null;
    }

    /**
     * @throws UnknownRoutingStrategyException
     */
    public function handlerFor(RoutingStrategy $strategy): RoutingStrategyHandler
    {
        return $this->resolve(
            $this->entryClass($strategy->value) ?? throw new UnknownRoutingStrategyException($strategy->value),
        );
    }

    protected function configKey(): string
    {
        return 'routing.strategy_handlers';
    }

    /**
     * @return class-string<RoutingStrategyHandler>
     */
    protected function contract(): string
    {
        return RoutingStrategyHandler::class;
    }
}
