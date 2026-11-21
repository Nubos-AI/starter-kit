<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle\SchemaVersion;

use App\Contracts\ConfigBundle\BundleSchemaMigratorInterface;

class V2ToV3Migrator implements BundleSchemaMigratorInterface
{
    public function fromVersion(): int
    {
        return 2;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tree
     * @return array<string, array<string, mixed>>
     */
    public function migrate(array $tree): array
    {
        return $tree;
    }
}
