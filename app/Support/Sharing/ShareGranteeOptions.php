<?php

declare(strict_types=1);

namespace App\Support\Sharing;

use App\Enums\Sharing\ShareGranteeType;
use App\Models\User;
use App\Support\Authorization\TenantRoleOptions;
use App\Support\Teams\TenantTeamOptions;
use App\Support\Users\TenantUserOptions;

class ShareGranteeOptions
{
    public function __construct(
        private readonly TenantUserOptions $userOptions,
        private readonly TenantTeamOptions $teamOptions,
        private readonly TenantRoleOptions $roleOptions,
    ) {}

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function forUser(User $actingUser, string $term = ''): array
    {
        return [
            ShareGranteeType::User->value => $this->userOptions->forUser($actingUser, $term),
            ShareGranteeType::Team->value => array_values($this->teamOptions->forUser($actingUser)),
            ShareGranteeType::Role->value => $this->roleOptions->assignable(),
        ];
    }
}
