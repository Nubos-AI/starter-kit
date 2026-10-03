<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Support\Abstracts\ConfigDrivenRegistry;

/**
 * @extends ConfigDrivenRegistry<TimelineSourceInterface>
 */
class TimelineSourceRegistry extends ConfigDrivenRegistry
{
    private string $keyPattern = '/^[a-z][a-z0-9_]*$/';

    public function hasSource(string $key): bool
    {
        return $this->entryClass($key) !== null;
    }

    /**
     * @throws UnknownTimelineSourceException
     */
    public function sourceFor(string $key): TimelineSourceInterface
    {
        return $this->resolve($this->entryClass($key) ?? throw new UnknownTimelineSourceException($key));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $sources = config($this->configKey());

        if (!is_array($sources)) {
            return [];
        }

        $keys = [];

        foreach (array_keys($sources) as $key) {
            if (is_string($key) && $this->entryClass($key) !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return class-string<TimelineSourceInterface>|null
     */
    protected function entryClass(string $key): ?string
    {
        return preg_match($this->keyPattern, $key) === 1 ? parent::entryClass($key) : null;
    }

    protected function configKey(): string
    {
        return 'timeline.sources';
    }

    /**
     * @return class-string<TimelineSourceInterface>
     */
    protected function contract(): string
    {
        return TimelineSourceInterface::class;
    }
}
