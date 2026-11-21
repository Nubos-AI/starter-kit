<?php

declare(strict_types=1);

namespace App\DTOs\ConfigBundle;

use InvalidArgumentException;

readonly class BundleManifest
{
    /**
     * @param  array<string, int>  $artifactCounts
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $schemaVersion,
        public string $sourceLabel,
        public array $artifactCounts,
        public array $warnings = [],
    ) {
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException("A bundle manifest needs a schema version of at least one, got {$schemaVersion}.");
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function fromArray(array $manifest): self
    {
        $sourceLabel = (string) ($manifest['source_label'] ?? '');

        if ($sourceLabel === '') {
            throw new InvalidArgumentException(__('i18n.backend.dtos.config_bundle.bundle_manifest.a_bundle_manifest_array_needs_a_source_label'));
        }

        $rawCounts = $manifest['artifact_counts'] ?? [];

        if (!is_array($rawCounts)) {
            throw new InvalidArgumentException(__('i18n.backend.dtos.config_bundle.bundle_manifest.a_bundle_manifest_array_needs_its_artifact_counts_as'));
        }

        $counts = [];

        foreach ($rawCounts as $kind => $count) {
            if (!is_string($kind)) {
                throw new InvalidArgumentException(__('i18n.backend.dtos.config_bundle.bundle_manifest.a_bundle_manifest_array_needs_its_artifact_counts_keyed'));
            }

            if (!is_int($count)) {
                throw new InvalidArgumentException("A bundle manifest array needs a whole number as the artifact count for kind {$kind}.");
            }

            $counts[$kind] = $count;
        }

        $rawWarnings = $manifest['warnings'] ?? [];

        if (!is_array($rawWarnings)) {
            throw new InvalidArgumentException(__('i18n.backend.dtos.config_bundle.bundle_manifest.a_bundle_manifest_array_needs_its_warnings_as_a'));
        }

        $warnings = [];

        foreach ($rawWarnings as $warning) {
            if (!is_string($warning)) {
                throw new InvalidArgumentException(__('i18n.backend.dtos.config_bundle.bundle_manifest.a_bundle_manifest_array_needs_every_warning_as_a'));
            }

            $warnings[] = $warning;
        }

        return new self(
            schemaVersion: (int) ($manifest['schema_version'] ?? 0),
            sourceLabel: $sourceLabel,
            artifactCounts: $counts,
            warnings: $warnings,
        );
    }

    /**
     * @return array{schema_version: int, source_label: string, artifact_counts: array<string, int>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'source_label' => $this->sourceLabel,
            'artifact_counts' => $this->artifactCounts,
            'warnings' => $this->warnings,
        ];
    }
}
