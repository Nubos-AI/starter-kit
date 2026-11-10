<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\DTOs\Recurrence\RecurrenceRule;
use App\Models\ReminderTask;
use App\Models\User;
use App\Support\Links\SourceLinkValidator;
use App\Support\Recurrence\RecurrenceExpander;
use App\Support\Recurrence\RecurrenceRuleValidator;
use App\Support\Reminders\ReminderAuthority;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class UpdateReminderTaskAction
{
    public function __construct(
        private readonly ReminderAuthority $reminderAuthority,
        private readonly SourceLinkValidator $sourceLinkValidator,
        private readonly RecurrenceRuleValidator $recurrenceRuleValidator,
        private readonly RecurrenceExpander $recurrenceExpander,
        private readonly CopyReminderOccurrenceAction $copyReminderOccurrence,
    ) {}

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
                ...array_map(
                    static fn (array $rules): array => ['sometimes', ...$rules],
                    [...$this->sourceLinkValidator->rules(), ...$this->recurrenceRuleValidator->rules()],
                ),
                ...$this->recurrenceRuleValidator->scopeRules(),
            ]
        )->validate();

        $actingUser = Auth::user();
        $actingUser = $actingUser instanceof User ? $actingUser : null;
        $recordId = $validated['record_id'] ?? null;

        if ($recordId !== $reminder->record_id) {
            $this->reminderAuthority->assertMayLinkRecord($actingUser, $recordId);
        }

        $this->sourceLinkValidator->assertChangedSourceAccessible($actingUser, $validated, $reminder->source_type, $reminder->source_id);

        $occurrenceAt = $this->recurrenceRuleValidator->occurrenceOf($validated, $reminder);
        $changes = Arr::except($validated, RecurrenceRuleValidator::$scopeFields);

        if ($occurrenceAt instanceof CarbonImmutable) {
            return DB::transaction(fn (): ReminderTask => $this->updateOccurrence($reminder, $occurrenceAt, Arr::except($changes, RecurrenceRuleValidator::$fields)));
        }

        if ($this->recurrenceRuleValidator->touches($changes) || Arr::has($changes, 'due_at')) {
            $dueAt = Arr::has($changes, 'due_at') ? $changes['due_at'] : $reminder->due_at;
            $startsAt = $dueAt === null ? null : Carbon::parse($dueAt);
            $recurrence = $this->recurrenceRuleValidator->attributes(
                [...$reminder->recurrenceAttributes(), ...Arr::only($changes, RecurrenceRuleValidator::$fields)],
                $startsAt,
            );

            $exceptions = $this->recurrenceRuleValidator->exceptionsAfterChange($reminder, $recurrence, $startsAt);

            $changes = [
                ...$changes,
                ...$recurrence,
                'recurrence_anchor_at' => $recurrence['recurrence_frequency'] === null ? null : $startsAt,
                ...($exceptions === null && $reminder->recurrence_exceptions === null ? [] : ['recurrence_exceptions' => $exceptions]),
            ];
        }

        return DB::transaction(function () use ($reminder, $changes): ReminderTask {
            $reminder->fill([...$changes, ...$this->rearmedOnMove($reminder, $changes)])->save();

            return $reminder->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateOccurrence(ReminderTask $reminder, CarbonImmutable $occurrenceAt, array $changes): ReminderTask
    {
        /** @var RecurrenceRule $rule */
        $rule = $reminder->recurrenceRule();
        $isCurrent = $reminder->due_at !== null && $occurrenceAt->equalTo($reminder->due_at);
        $next = $isCurrent ? $this->recurrenceExpander->after($reminder->seriesAnchor() ?? $occurrenceAt, $rule, $occurrenceAt) : null;

        if ($isCurrent && !$next instanceof CarbonImmutable) {
            $reminder->fill([...$changes, ...$this->rearmedOnMove($reminder, $changes), ...$reminder->detachedAttributes($occurrenceAt), 'series_id' => null, 'series_occurrence_at' => null, 'recurrence_anchor_at' => null])->save();

            return $reminder->refresh();
        }

        $moved = $this->copyReminderOccurrence->execute($reminder, [
            ...$changes,
            'due_at' => Arr::has($changes, 'due_at') ? $changes['due_at'] : $occurrenceAt,
            ...$reminder->detachedAttributes($occurrenceAt),
        ]);

        $reminder->update($isCurrent ? ['due_at' => $next, 'notified_at' => null, 'notified_channels' => null] : ['recurrence_exceptions' => $rule->exceptionsWith($occurrenceAt)]);

        return $moved;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array{notified_at?: null, notified_channels?: null}
     */
    private function rearmedOnMove(ReminderTask $reminder, array $changes): array
    {
        if (!Arr::has($changes, 'due_at')) {
            return [];
        }

        $dueAt = $changes['due_at'] === null ? null : Carbon::parse($changes['due_at']);
        $isUnchanged = $dueAt === null ? $reminder->due_at === null : $reminder->due_at !== null && $dueAt->equalTo($reminder->due_at);

        return $isUnchanged ? [] : ['notified_at' => null, 'notified_channels' => null];
    }
}
