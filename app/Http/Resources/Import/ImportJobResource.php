<?php

declare(strict_types=1);

namespace App\Http\Resources\Import;

use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ImportJob */
class ImportJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'status' => $this->status->value,
            'originalFilename' => $this->original_filename,
            'format' => $this->format,
            'totalRows' => $this->total_rows,
            'createdCount' => $this->created_count,
            'updatedCount' => $this->updated_count,
            'errorCount' => $this->error_count,
            'hasErrorReport' => $this->error_report_path !== null,
            'startedAt' => $this->started_at?->toISOString(),
            'finishedAt' => $this->finished_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
