<?php

declare(strict_types=1);

namespace App\Http\Resources\Export;

use App\Models\ExportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExportJob */
class ExportJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'status' => $this->status->value,
            'format' => $this->format->value,
            'rowCount' => $this->row_count,
            'hasFile' => $this->result_path !== null,
            'startedAt' => $this->started_at?->toISOString(),
            'finishedAt' => $this->finished_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
