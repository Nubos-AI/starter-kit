<?php

declare(strict_types=1);

namespace App\Http\Resources\Maintenance;

use App\Models\MaintenanceLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceLock
 */
class MaintenanceLockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason->value,
            'reasonLabel' => $this->reason->label(),
            'note' => $this->note,
            'acquiredAt' => $this->acquired_at->toISOString(),
            'acquiredByName' => $this->whenLoaded('acquiredBy', fn (): ?string => $this->acquiredBy?->name),
        ];
    }
}
