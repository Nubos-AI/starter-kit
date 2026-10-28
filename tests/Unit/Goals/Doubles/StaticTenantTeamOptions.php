<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Models\User;
use App\Support\Teams\TenantTeamOptions;

class StaticTenantTeamOptions extends TenantTeamOptions
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(private readonly array $options = []) {}

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function forUser(User $actingUser): array
    {
        return $this->options;
    }
}
