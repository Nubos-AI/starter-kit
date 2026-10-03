<?php

declare(strict_types=1);

namespace App\Actions\Notes;

use App\Models\RecordNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UpdateRecordNoteAction
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(RecordNote $note, array $input): RecordNote
    {
        $body = $input['body'] ?? null;

        $validated = Validator::make(
            ['body' => is_string($body) ? trim($body) : $body],
            ['body' => ['required', 'string', 'max:10000']]
        )->validate();

        return DB::transaction(function () use ($note, $validated): RecordNote {
            $note->fill($validated)->save();

            return $note->refresh();
        });
    }
}
