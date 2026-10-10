<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\CustomRecord;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\RollupOwnerStarter;
use App\Traits\Engine\TranslatesUniqueViolations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RestoreRecordAction
{
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly RollupOwnerStarter $rollupStarter,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(CustomRecord $record): CustomRecord
    {
        $deletedAt = $record->deleted_at?->toIso8601String();

        try {
            return DB::transaction(function () use ($record, $deletedAt): CustomRecord {
                $record->update(['deletion_reason' => null]);
                $record->restore();

                $this->auditRecorder->record(
                    $record,
                    ['deleted_at' => $deletedAt],
                    ['deleted_at' => null],
                    $record->version,
                );

                $this->rollupStarter->startForParentsOf($record);

                return $record;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'record' => __('i18n.backend.actions.engine.restore_record_action.restore_failed_the_record_number_or_external_reference_is'),
                ]);
            }

            throw $exception;
        }
    }
}
