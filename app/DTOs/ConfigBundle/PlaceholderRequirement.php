<?php

declare(strict_types=1);

namespace App\DTOs\ConfigBundle;

readonly class PlaceholderRequirement
{
    public function __construct(
        public string $artifactKey,
        public string $label,
        public string $currentValue,
    ) {}

    /**
     * @return array{artifact_key: string, label: string, current_value: string}
     */
    public function toArray(): array
    {
        return [
            'artifact_key' => $this->artifactKey,
            'label' => $this->label,
            'current_value' => $this->currentValue,
        ];
    }
}
