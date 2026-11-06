<?php

declare(strict_types=1);

namespace App\DTOs\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;
use InvalidArgumentException;
use JsonException;

readonly class BundleArtifact
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public ArtifactKind $kind,
        public string $key,
        public array $payload,
        public array $dependsOn = [],
    ) {
        if ($key === '') {
            throw new InvalidArgumentException("A bundle artifact of kind {$kind->value} needs a business key.");
        }
    }

    /**
     * @throws JsonException
     */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR));
    }
}
