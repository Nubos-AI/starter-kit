<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attachment */
class AttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fileName' => $this->original_name,
            'mimeType' => $this->mime,
            'size' => $this->size,
            'fieldKey' => $this->field_key,
            'createdAt' => $this->created_at?->toISOString(),
            'uploadedBy' => $this->whenLoaded('uploadedBy', fn (): ?array => $this->uploadedBy === null ? null : [
                'id' => $this->uploadedBy->getKey(),
                'label' => $this->uploadedBy->name,
            ]),
            'canDelete' => $request->user()?->can('delete', $this->resource) ?? false,
        ];
    }
}
