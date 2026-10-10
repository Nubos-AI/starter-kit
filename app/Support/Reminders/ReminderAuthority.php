<?php

declare(strict_types=1);

namespace App\Support\Reminders;

use App\Models\CustomRecord;
use App\Models\ReminderTask;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReminderAuthority
{
    public function __construct(private readonly ReminderRecordSource $recordSource) {}

    public function manages(User $user, ReminderTask $reminder): bool
    {
        $userId = (string) $user->getKey();

        return in_array($userId, [
            $reminder->owner_id,
            $reminder->creator_id,
            (string) $reminder->assignee_id,
        ], true);
    }

    /**
     * @throws ValidationException
     */
    public function assertMayLinkRecord(?User $user, mixed $recordId): void
    {
        if (!$user instanceof User || !is_string($recordId) || $recordId === '') {
            return;
        }

        $record = $this->recordSource->find($recordId);

        if ($record instanceof CustomRecord && $user->can('view', $record)) {
            return;
        }

        throw ValidationException::withMessages([
            'record_id' => __('validation.exists', ['attribute' => 'record id']),
        ]);
    }
}
