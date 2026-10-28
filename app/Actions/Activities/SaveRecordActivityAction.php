<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Models\CustomRecord;
use App\Models\RecordActivity;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\Timeline\TimelineRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SaveRecordActivityAction
{
    public function __construct(private readonly TimelineRecorder $timelineRecorder) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(User $actor, CustomRecord $record, array $input, ?RecordActivity $activity = null): RecordActivity
    {
        Gate::forUser($actor)->authorize('update', $record);
        abort_if($record->trashed(), 404);
        abort_if($activity !== null && $activity->record_id !== $record->id, 404);

        $validated = Validator::make($input, [
            'subject' => ['required', 'string', 'max:255'],
            'occurred_at' => ['required', 'date'],
            'result' => ['nullable', 'string', 'max:20000'],
            'activity_type_id' => [
                'required',
                'string',
                Rule::exists('activity_types', 'id')
                    ->where('tenant_id', $record->tenant_id)
                    ->when(
                        $activity?->activity_type_id !== ($input['activity_type_id'] ?? null),
                        fn ($rule) => $rule->whereNull('deleted_at')
                    ),
            ],
            'assignee_id' => [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where('tenant_id', $record->tenant_id)
                    ->whereNull('deleted_at'),
            ],
        ])->validate();

        $validated['occurred_at'] = Carbon::parse($validated['occurred_at'])->utc();

        return DB::transaction(function () use ($actor, $record, $activity, $validated): RecordActivity {
            $saved = $activity ?? new RecordActivity;
            $saved->fill($validated);

            if (!$saved->exists) {
                $saved->tenant_id = $record->tenant_id;
                $saved->record_id = $record->id;
                $saved->creator_id = $actor->id;
            }

            $saved->save();

            TimelineEntry::query()
                ->where('record_id', $record->id)
                ->where('source_key', 'activity')
                ->where('source_id', $saved->id)
                ->delete();

            $this->timelineRecorder->record($record, 'activity', [[
                'source_id' => $saved->id,
                'occurred_at' => $saved->occurred_at,
            ]]);

            return $saved->load(['activityType', 'assignee']);
        });
    }
}
