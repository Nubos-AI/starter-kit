<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

use App\Contracts\Dashboards\DashboardTileSourceInterface;

class DashboardTileSources
{
    /**
     * @param  iterable<DashboardTileSourceInterface>  $sources
     */
    public function __construct(private readonly iterable $sources = []) {}

    public function find(string $key): ?DashboardTileSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return list<DashboardTileSourceInterface>
     */
    public function all(): array
    {
        $sources = [];

        foreach ($this->sources as $source) {
            $sources[] = $source;
        }

        return $sources;
    }
}
