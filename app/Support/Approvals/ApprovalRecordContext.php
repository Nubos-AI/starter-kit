<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Models\ApprovalProcess;
use Illuminate\Database\Eloquent\Model;

class ApprovalRecordContext
{
    /** @return list<string> */
    public function forAnchor(Model $anchor): array
    {
        return [];
    }

    /** @return list<string> */
    public function fields(ApprovalProcess $process, bool $includeConditions = false): array
    {
        return [];
    }

    public function transitionLabel(ApprovalProcess $process): string
    {
        return '—';
    }

    public function stageLabel(?string $id): ?string
    {
        return null;
    }
}
