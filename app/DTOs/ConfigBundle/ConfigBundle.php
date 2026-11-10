<?php

declare(strict_types=1);

namespace App\DTOs\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;
use InvalidArgumentException;

readonly class ConfigBundle
{
    /**
     * @param  list<BundleArtifact>  $artifacts
     */
    public function __construct(
        public BundleManifest $manifest,
        public array $artifacts,
    ) {
        $seen = [];

        foreach ($artifacts as $artifact) {
            $identifier = $artifact->kind->identifierFor($artifact->key);

            if (isset($seen[$identifier])) {
                throw new InvalidArgumentException("The bundle carries the artifact {$identifier} more than once.");
            }

            $seen[$identifier] = true;
        }
    }

    /**
     * @return list<BundleArtifact>
     */
    public function byKind(ArtifactKind $kind): array
    {
        return array_values(array_filter(
            $this->artifacts,
            static fn (BundleArtifact $artifact): bool => $artifact->kind === $kind,
        ));
    }

    public function find(ArtifactKind $kind, string $key): ?BundleArtifact
    {
        foreach ($this->artifacts as $artifact) {
            if ($artifact->kind === $kind && $artifact->key === $key) {
                return $artifact;
            }
        }

        return null;
    }
}
