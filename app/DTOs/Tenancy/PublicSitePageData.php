<?php

declare(strict_types=1);

namespace App\DTOs\Tenancy;

readonly class PublicSitePageData
{
    /**
     * @param  array<string, mixed>  $props
     */
    public function __construct(
        public string $component,
        public array $props = [],
    ) {}
}
