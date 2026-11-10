<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\CustomRecord;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Modules\RecordExtensions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeleteRecordAction
{
    public function __construct(private readonly RollupOwnerStarter $rollupStarter, private readonly RecordExtensions $extensions) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(CustomRecord $record, array $input = []): void
    {
        $required = $record->objectType->requires_deletion_reason;

        $validated = Validator::make(
            $input,
            [
                'deletion_reason' => [$required ? 'required' : 'nullable', 'string', 'max:255'],
            ]
        )->validate();

        $reason = $validated['deletion_reason'] ?? null;

        DB::transaction(function () use ($record, $reason): void {
            $this->extensions->deleting($record);
            if ($reason !== null) {
                $record->update(['deletion_reason' => $reason]);
            }

            $record->delete();

            $this->rollupStarter->startForParentsOf($record);
        });
    }
}
