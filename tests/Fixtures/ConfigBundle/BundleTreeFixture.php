<?php

declare(strict_types=1);

namespace Tests\Fixtures\ConfigBundle;

use Illuminate\Support\Str;

class BundleTreeFixture
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function v1(): array
    {
        return [
            'manifest.yaml' => [
                'schema_version' => 1,
                'environment' => 'production',
                'source_label' => 'Produktion GmbH',
                'artifact_counts' => [
                    'object_type' => 3,
                    'field' => 12,
                ],
                'warnings' => [
                    'Der Bezeichner "owner_id" konnte nicht aufgelöst werden.',
                ],
                'generated_at' => '2026-01-01T00:00:00+00:00',
            ],
            'object_types/deal.yaml' => [
                'name' => 'Deal',
                'fields' => ['title', 'amount'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function v2(): array
    {
        return [
            'manifest.yaml' => [
                'schema_version' => 1,
                'environment' => 'production',
                'source_label' => 'Produktion GmbH',
                'artifact_counts' => [
                    'object_type' => 3,
                    'field' => 12,
                ],
                'warnings' => [
                    'Der Bezeichner "owner_id" konnte nicht aufgelöst werden.',
                ],
            ],
            'object_types/deal.yaml' => [
                'name' => 'Deal',
                'fields' => ['title', 'amount'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filterDefinition
     * @return array<string, array<string, mixed>>
     */
    public static function v2WithTeamAccessRule(string $teamSlug, string $objectTypeKey, array $filterDefinition): array
    {
        return [
            'manifest.yaml' => [
                'schema_version' => 2,
                'environment' => 'production',
                'source_label' => 'staging-eu',
                'artifact_counts' => [
                    'team-record-access-rules' => 1,
                ],
                'warnings' => [],
            ],
            'team-record-access-rules/'.$teamSlug.'__'.Str::slug($objectTypeKey).'.yaml' => [
                'key' => "{$teamSlug}:{$objectTypeKey}",
                'is_active' => true,
                'inheritance' => 'intersect',
                'filter_definition' => $filterDefinition,
            ],
        ];
    }
}
