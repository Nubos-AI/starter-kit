<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\MergeRule;

class DeleteMergeRuleAction
{
    public function execute(MergeRule $rule): void
    {
        $rule->delete();
    }
}
