<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Records\SyncRecordCollaboratorsAction;
use App\Models\CustomRecord;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateRecordWithCollaboratorsAction
{
    public function __construct(
        private readonly CreateRecordAction $createRecord,
        private readonly SyncRecordCollaboratorsAction $syncCollaborators,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $collaborators
     *
     * @throws Throwable
     */
    public function execute(array $input, ?array $collaborators, bool $isUserInput = false): CustomRecord
    {
        return DB::transaction(function () use ($input, $collaborators, $isUserInput): CustomRecord {
            $record = $this->createRecord->execute($input, isUserInput: $isUserInput);

            if ($collaborators !== null) {
                $this->syncCollaborators->execute($record, $collaborators);
            }

            return $record;
        });
    }
}
