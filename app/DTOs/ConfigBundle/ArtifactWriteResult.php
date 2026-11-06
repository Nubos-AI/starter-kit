<?php

declare(strict_types=1);

namespace App\DTOs\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;

readonly class ArtifactWriteResult
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public ArtifactKind $kind,
        public string $key,
        public ArtifactWriteAction $action,
        public ?string $modelId,
        public array $notes = [],
        public ?string $awaitedReference = null,
    ) {}

    public function awaitsReference(): bool
    {
        return $this->awaitedReference !== null;
    }
}
