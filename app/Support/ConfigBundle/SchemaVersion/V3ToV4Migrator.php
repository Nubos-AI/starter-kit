<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle\SchemaVersion;

use App\Contracts\ConfigBundle\BundleSchemaMigratorInterface;
use App\Enums\ConfigBundle\ArtifactKind;

class V3ToV4Migrator implements BundleSchemaMigratorInterface
{
    public function fromVersion(): int
    {
        return 3;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tree
     * @return array<string, array<string, mixed>>
     */
    public function migrate(array $tree): array
    {
        unset($tree['manifest.yaml']['environment']);

        $fieldDirectory = ArtifactKind::FieldDefinitions->value.'/';

        foreach (array_keys($tree) as $relative) {
            if (str_starts_with($relative, $fieldDirectory)) {
                unset($tree[$relative]['is_personal_data']);
            }
        }

        return $tree;
    }
}
