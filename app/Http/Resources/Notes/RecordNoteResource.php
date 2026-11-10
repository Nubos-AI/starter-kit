<?php

declare(strict_types=1);

namespace App\Http\Resources\Notes;

use App\Models\RecordNote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecordNote
 */
class RecordNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RecordNote $note */
        $note = $this->resource;

        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $this->userRef($note->author),
            'createdAt' => $note->created_at?->toISOString(),
            'updatedAt' => $note->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function userRef(?User $user): ?array
    {
        if (!$user instanceof User) {
            return null;
        }

        return [
            'id' => $user->getKey(),
            'label' => $user->name,
        ];
    }
}
