<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Models\CustomRecord;
use App\Models\RecordActivity;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\Links\SourceLinkValidator;
use App\Support\Tenancy\TenantContext;
use App\Support\Timeline\TimelineRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SaveRecordActivityAction
{
    public function __construct(
        private readonly TimelineRecorder $timelineRecorder,
        private readonly SourceLinkValidator $sourceLinkValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(User $actor, ?CustomRecord $record, array $input, ?RecordActivity $activity = null): RecordActivity
    {
        $this->authorizeTarget($actor, $record, $activity);

        $tenantId = $record !== null ? $record->tenant_id : TenantContext::currentId($actor->tenant_id);

        $validated = Validator::make($input, [
            'subject' => ['required', 'string', 'max:255'],
            'occurred_at' => ['required', 'date'],
            'result' => ['nullable', 'string', 'max:20000'],
            'activity_type_id' => [
                'required',
                'string',
                Rule::exists('activity_types', 'id')
                    ->where('tenant_id', $tenantId)
                    ->when(
                        $activity?->activity_type_id !== ($input['activity_type_id'] ?? null),
                        fn ($rule) => $rule->whereNull('deleted_at')
                    ),
            ],
            'assignee_id' => [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            ...$this->sourceLinkValidator->rules($record === null),
        ])->validate();

        $this->sourceLinkValidator->assertChangedSourceAccessible($actor, $validated, $activity?->source_type, $activity?->source_id);
        $this->sourceLinkValidator->assertChangedActivityTargetAllowed($actor, $validated, $record, $activity?->source_type, $activity?->source_id);

        $validated['occurred_at'] = Carbon::parse($validated['occurred_at'])->utc();

        return DB::transaction(function () use ($actor, $record, $activity, $validated, $tenantId): RecordActivity {
            $saved = $activity ?? new RecordActivity;
            $saved->fill($validated);

            if (!$saved->exists) {
                $saved->tenant_id = (string) $tenantId;
                $saved->record_id = $record?->id;
                $saved->creator_id = $actor->id;
            }

            $saved->save();

            if ($record instanceof CustomRecord) {
                $this->recordOnTimeline($record, $saved);
            }

            return $saved->load(['activityType', 'assignee']);
        });
    }

    private function authorizeTarget(User $actor, ?CustomRecord $record, ?RecordActivity $activity): void
    {
        if ($record instanceof CustomRecord) {
            Gate::forUser($actor)->authorize('update', $record);
            abort_if($record->trashed(), 404);
            abort_if($activity !== null && $activity->record_id !== $record->id, 404);

            return;
        }

        if ($activity instanceof RecordActivity) {
            abort_if($activity->record_id !== null, 404);
            Gate::forUser($actor)->authorize('update', $activity);

            return;
        }

        Gate::forUser($actor)->authorize('create', RecordActivity::class);
    }

    private function recordOnTimeline(CustomRecord $record, RecordActivity $activity): void
    {
        TimelineEntry::query()
            ->where('record_id', $record->id)
            ->where('source_key', 'activity')
            ->where('source_id', $activity->id)
            ->delete();

        $this->timelineRecorder->record($record, 'activity', [[
            'source_id' => $activity->id,
            'occurred_at' => $activity->occurred_at,
        ]]);
    }
}
