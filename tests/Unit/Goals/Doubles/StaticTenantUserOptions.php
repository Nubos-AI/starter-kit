<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Models\User;
use App\Support\Users\TenantUserOptions;

class StaticTenantUserOptions extends TenantUserOptions
{
    /**
     * @param  list<array{value: string, label: string, description: string, avatar: array{name: string}}>  $options
     */
    public function __construct(private readonly array $options = []) {}

    /**
     * @return list<array{value: string, label: string, description: string, avatar: array{name: string}}>
     */
    public function forUser(User $actingUser, string $term = ''): array
    {
        return $this->options;
    }
}
