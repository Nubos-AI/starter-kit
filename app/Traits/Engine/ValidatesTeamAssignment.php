<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ValidatesTeamAssignment
{
    private function teamRule(string $tenantId): Exists
    {
        return Rule::exists('teams', 'id')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at');
    }
}
