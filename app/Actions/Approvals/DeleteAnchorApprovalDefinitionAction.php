<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Enums\Approvals\ApprovalAnchorKind;
use App\Models\ApprovalDefinition;

class DeleteAnchorApprovalDefinitionAction
{
    public function execute(ApprovalAnchorKind $kind): void
    {
        $definition = ApprovalDefinition::query()
            ->where('anchor_type', $kind->modelClass())
            ->whereNull('anchor_id')
            ->first();

        if (!$definition instanceof ApprovalDefinition) {
            return;
        }

        $definition->delete();
    }
}
