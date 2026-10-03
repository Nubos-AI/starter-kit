<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\ConfigBundle\PlaceholderRequirement;
use App\Enums\ConfigBundle\ArtifactKind;

class BundlePlaceholderResolver
{
    private string $targetField = 'target_url';

    /**
     * @return list<PlaceholderRequirement>
     */
    public function collect(ConfigBundle $bundle): array
    {
        return array_map(
            fn (BundleArtifact $artifact): PlaceholderRequirement => new PlaceholderRequirement(
                $artifact->key,
                $artifact->key,
                is_scalar($artifact->payload[$this->targetField] ?? null) ? (string) $artifact->payload[$this->targetField] : '',
            ),
            $bundle->byKind(ArtifactKind::WebhookSubscriptions),
        );
    }

    /**
     * @param  array<string, string>  $targetsByKey
     */
    public function resolve(ConfigBundle $bundle, array $targetsByKey): ConfigBundle
    {
        return new ConfigBundle(
            $bundle->manifest,
            array_map(
                fn (BundleArtifact $artifact): BundleArtifact => $artifact->kind === ArtifactKind::WebhookSubscriptions && array_key_exists($artifact->key, $targetsByKey)
                    ? new BundleArtifact(
                        $artifact->kind,
                        $artifact->key,
                        [...$artifact->payload, $this->targetField => $targetsByKey[$artifact->key]],
                        $artifact->dependsOn,
                    )
                    : $artifact,
                $bundle->artifacts,
            ),
        );
    }
}
