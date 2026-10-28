<?php

declare(strict_types=1);

namespace App\Actions\Notes;

use App\Models\RecordNote;

class DeleteRecordNoteAction
{
    public function execute(RecordNote $note): void
    {
        $note->delete();
    }
}
