<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomRecord;
use App\Models\ReminderTask;
use App\Models\ReminderType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReminderTask
 */
class ReminderTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReminderTask $reminder */
        $reminder = $this->resource;

        return [
            'id' => $reminder->id,
            'subject' => $reminder->subject,
            'note' => $reminder->note,
            'type' => $this->typeRef($reminder->reminderType),
            'dueAt' => $reminder->due_at?->toISOString(),
            'doneAt' => $reminder->done_at?->toISOString(),
            'owner' => $this->userRef($reminder->owner),
            'assignee' => $this->userRef($reminder->assignee),
            'record' => $this->recordRef($request, $reminder->record),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function typeRef(?ReminderType $type): ?array
    {
        if (!$type instanceof ReminderType) {
            return null;
        }

        return [
            'id' => $type->getKey(),
            'label' => $type->name,
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

    /**
     * @return array<string, string|bool|null>|null
     */
    private function recordRef(Request $request, ?CustomRecord $record): ?array
    {
        if (!$record instanceof CustomRecord) {
            return null;
        }

        if ($record->trashed() && !$this->mayViewDeletedRecords($request)) {
            return null;
        }

        return [
            'id' => $record->getKey(),
            'label' => $record->record_number,
            'deleted' => $record->trashed(),
        ];
    }

    private function mayViewDeletedRecords(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->isEscalatedAuthority();
    }
}
