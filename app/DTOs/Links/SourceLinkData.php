<?php

declare(strict_types=1);

namespace App\DTOs\Links;

readonly class SourceLinkData
{
    public function __construct(
        public string $label,
        public string $href,
    ) {}
}
