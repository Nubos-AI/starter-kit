<?php

declare(strict_types=1);

namespace App\Contracts\ConfigBundle;

interface BundleSchemaMigratorInterface
{
    public function fromVersion(): int;

    /**
     * @param  array<string, array<string, mixed>>  $tree
     * @return array<string, array<string, mixed>>
     */
    public function migrate(array $tree): array;
}
