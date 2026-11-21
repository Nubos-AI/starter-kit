<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Records\SyncRecordCollaboratorsAction;
use App\Models\CustomRecord;
use Illuminate\Support\Facades\DB;
use Throwable;

class UpdateRecordWithCollaboratorsAction
{
    public function __construct(
        private readonly UpdateRecordAction $updateRecord,
        private readonly SyncRecordCollaboratorsAction $syncCollaborators,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $collaborators
     *
     * @throws Throwable
     */
    public function execute(CustomRecord $record, array $input, ?array $collaborators): CustomRecord
    {
        return DB::transaction(function () use ($record, $input, $collaborators): CustomRecord {
            $updated = $this->updateRecord->execute($record, $input);

            if ($collaborators !== null) {
                $this->syncCollaborators->execute($updated, $collaborators);
            }

            return $updated;
        });
    }
}
