<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle\SchemaVersion;

use App\Contracts\ConfigBundle\BundleSchemaMigratorInterface;

class V1ToV2Migrator implements BundleSchemaMigratorInterface
{
    public function fromVersion(): int
    {
        return 1;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tree
     * @return array<string, array<string, mixed>>
     */
    public function migrate(array $tree): array
    {
        unset($tree['manifest.yaml']['generated_at']);

        return $tree;
    }
}
