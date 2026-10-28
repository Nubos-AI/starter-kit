<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Models\User;
use App\Support\Reminders\ReminderAuthority;
use App\Support\Tenancy\TenantContext;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class CreateReminderTaskAction
{
    public function __construct(
        private readonly ReminderTimelineWriter $timelineWriter,
        private readonly ReminderAuthority $reminderAuthority,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(array $input): ReminderTask
    {
        $tenantId = TenantContext::currentId(Auth::user()?->tenant_id);

        $validated = Validator::make(
            $input,
            [
                'subject' => ['required', 'string', 'max:255'],
                'due_at' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
                'reminder_type_id' => ['nullable', 'string', Rule::exists('reminder_types', 'id')->where('tenant_id', $tenantId)],
                'assignee_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
                'record_id' => ['nullable', 'string', Rule::exists('custom_records', 'id')->where('tenant_id', $tenantId)],
            ]
        )->validate();

        $actingUser = Auth::user();

        $this->reminderAuthority->assertMayLinkRecord(
            $actingUser instanceof User ? $actingUser : null,
            $validated['record_id'] ?? null,
        );

        $userId = (string) Auth::id();

        return DB::transaction(function () use ($validated, $userId): ReminderTask {
            $reminder = ReminderTask::query()->create([
                'creator_id' => $userId,
                'owner_id' => $userId,
                'assignee_id' => $validated['assignee_id'] ?? $userId,
                'record_id' => $validated['record_id'] ?? null,
                'reminder_type_id' => $validated['reminder_type_id'] ?? null,
                'due_at' => $validated['due_at'] ?? null,
                'subject' => $validated['subject'],
                'note' => $validated['note'] ?? null,
            ]);

            $this->timelineWriter->record($reminder, ReminderEventState::Created, $reminder->created_at);

            return $reminder;
        });
    }
}
