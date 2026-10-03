<?php

declare(strict_types=1);

namespace App\Policies\Notes;

use App\Models\CustomRecord;
use App\Models\RecordNote;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RecordNotePolicy
{
    public function view(User $user, RecordNote $note): bool
    {
        if ($note->tenant_id !== $user->tenant_id) {
            return false;
        }

        $record = $note->record;

        return $record instanceof CustomRecord && Gate::forUser($user)->allows('view', $record);
    }

    public function update(User $user, RecordNote $note): bool
    {
        return $this->view($user, $note)
            && ($this->isAuthor($user, $note) || $user->isEscalatedAuthority());
    }

    public function delete(User $user, RecordNote $note): bool
    {
        return $this->view($user, $note)
            && ($this->isAuthor($user, $note) || $user->isEscalatedAuthority());
    }

    private function isAuthor(User $user, RecordNote $note): bool
    {
        return $note->author_id === $user->getKey();
    }
}
