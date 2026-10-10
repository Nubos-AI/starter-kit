<?php

declare(strict_types=1);

namespace App\Actions\Notes;

use App\Models\CustomRecord;
use App\Models\RecordNote;
use App\Support\Tenancy\TenantContext;
use App\Support\Timeline\NoteTimelineWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class CreateRecordNoteAction
{
    public function __construct(
        private readonly NoteTimelineWriter $timelineWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(array $input): RecordNote
    {
        $author = Auth::user();
        $tenantId = TenantContext::currentId($author?->tenant_id);
        $body = $input['body'] ?? null;

        $validated = Validator::make(
            [
                'record_id' => $input['record_id'] ?? null,
                'body' => is_string($body) ? trim($body) : $body,
            ],
            [
                'record_id' => ['required', 'string', Rule::exists('custom_records', 'id')->where('tenant_id', $tenantId)],
                'body' => ['required', 'string', 'max:10000'],
            ]
        )->validate();

        return DB::transaction(function () use ($validated, $author, $tenantId): RecordNote {
            $record = CustomRecord::query()->whereKey($validated['record_id'])->firstOrFail();

            $note = RecordNote::query()->create([
                'tenant_id' => $tenantId,
                'record_id' => $record->getKey(),
                'author_id' => $author?->getKey(),
                'body' => $validated['body'],
            ]);

            $this->timelineWriter->record($record, [['note' => $note, 'author_name' => $author?->name]]);

            return $note;
        });
    }
}
