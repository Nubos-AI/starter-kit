<?php

declare(strict_types=1);

namespace App\Actions\Aging;

use App\Models\AgingRule;

class DeleteAgingRuleAction
{
    public function execute(AgingRule $rule): void
    {
        $rule->delete();
    }
}
