<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\CustomRecord;
use Illuminate\Support\Facades\Validator;

class UpdateRecordCellAction
{
    public function __construct(private readonly UpdateRecordAction $updateRecord) {}

    /** @param array<string, mixed> $input */
    public function execute(CustomRecord $record, array $input): CustomRecord
    {
        $validated = Validator::make($input, [
            'field' => ['required', 'string'],
            'value' => ['present', 'nullable'],
        ])->validate();

        return $this->updateRecord->execute($record, [
            'data' => [$validated['field'] => $validated['value']],
            'version' => $input['version'] ?? null,
        ]);
    }
}
