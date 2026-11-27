<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeRecordPresence;
use Illuminate\Validation\ValidationException;

class DeleteObjectTypeAction
{
    public function __construct(private readonly ObjectTypeRecordPresence $recordPresence) {}

    /**
     * @throws ValidationException
     */
    public function execute(ObjectType $objectType): void
    {
        if ($this->recordPresence->holdsRecords($objectType)) {
            throw ValidationException::withMessages([
                'objectType' => __(ObjectTypeRecordPresence::$blockedByRecordsReason),
            ]);
        }

        $objectType->delete();
    }
}
