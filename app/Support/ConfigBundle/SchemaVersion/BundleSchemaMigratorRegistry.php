<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle\SchemaVersion;

use App\Contracts\ConfigBundle\BundleSchemaMigratorInterface;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use InvalidArgumentException;

class BundleSchemaMigratorRegistry
{
    /**
     * @var array<int, BundleSchemaMigratorInterface>
     */
    private array $migrators = [];

    /**
     * @param  list<BundleSchemaMigratorInterface>|null  $migrators
     */
    public function __construct(?array $migrators = null)
    {
        foreach ($migrators ?? $this->productionChain() as $migrator) {
            $fromVersion = $migrator->fromVersion();

            if (isset($this->migrators[$fromVersion])) {
                throw new InvalidArgumentException("Two bundle schema migrators declare the same fromVersion ({$fromVersion}).");
            }

            $this->migrators[$fromVersion] = $migrator;
        }
    }

    public function currentVersion(): int
    {
        return $this->migrators === [] ? 1 : max(array_keys($this->migrators)) + 1;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tree
     * @return array<string, array<string, mixed>>
     *
     * @throws UnsupportedBundleSchemaVersionException
     */
    public function upgrade(array $tree, int $fromVersion): array
    {
        $currentVersion = $this->currentVersion();

        if ($fromVersion < 1) {
            throw UnsupportedBundleSchemaVersionException::invalidVersion($fromVersion, $currentVersion);
        }

        if ($fromVersion > $currentVersion) {
            throw UnsupportedBundleSchemaVersionException::newerThanSupported($fromVersion, $currentVersion);
        }

        for ($version = $fromVersion; $version < $currentVersion; $version++) {
            if (!isset($this->migrators[$version])) {
                throw UnsupportedBundleSchemaVersionException::chainGap($fromVersion, $version, $currentVersion);
            }

            $tree = $this->migrators[$version]->migrate($tree);
        }

        return $tree;
    }

    /**
     * @return list<BundleSchemaMigratorInterface>
     */
    private function productionChain(): array
    {
        return [new V1ToV2Migrator, new V2ToV3Migrator, new V3ToV4Migrator];
    }
}
