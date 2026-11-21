<?php

declare(strict_types=1);

namespace App\DTOs\Tenancy;

use App\Models\Team;
use App\Models\Tenant;

readonly class SiteAddress
{
    public function __construct(
        public Tenant $tenant,
        public ?Team $team = null,
        public string $path = '',
    ) {}

    public function atPath(string $path): self
    {
        return new self($this->tenant, $this->team, $path);
    }
}
