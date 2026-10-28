<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Models\ReminderTask;
use App\Models\User;
use App\Support\Reminders\ReminderAuthority;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class UpdateReminderTaskAction
{
    public function __construct(private readonly ReminderAuthority $reminderAuthority) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(ReminderTask $reminder, array $input): ReminderTask
    {
        $tenantId = TenantContext::currentId($reminder->tenant_id);

        $validated = Validator::make(
            $input,
            [
                'subject' => ['sometimes', 'required', 'string', 'max:255'],
                'due_at' => ['sometimes', 'nullable', 'date'],
                'note' => ['sometimes', 'nullable', 'string'],
                'reminder_type_id' => ['sometimes', 'nullable', 'string', Rule::exists('reminder_types', 'id')->where('tenant_id', $tenantId)],
                'assignee_id' => ['sometimes', 'nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
                'record_id' => ['sometimes', 'nullable', 'string', Rule::exists('custom_records', 'id')->where('tenant_id', $tenantId)],
            ]
        )->validate();

        $actingUser = Auth::user();
        $recordId = $validated['record_id'] ?? null;

        if ($recordId !== $reminder->record_id) {
            $this->reminderAuthority->assertMayLinkRecord($actingUser instanceof User ? $actingUser : null, $recordId);
        }

        return DB::transaction(function () use ($reminder, $validated): ReminderTask {
            $reminder->fill($validated)->save();

            return $reminder->refresh();
        });
    }
}
