<?php

declare(strict_types=1);

namespace App\Http\Resources\Formulas;

use App\Models\FormulaBackfillRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FormulaBackfillRun
 */
class FormulaBackfillRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'status' => $this->status->value,
            'totalCount' => $this->total_count,
            'processedCount' => $this->processed_count,
            'errorCount' => $this->error_count,
            'finishedAt' => $this->finished_at?->toIso8601String(),
        ];
    }
}
