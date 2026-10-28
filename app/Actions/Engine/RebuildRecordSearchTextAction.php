<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\Engine\RecordBackfillKind;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use Illuminate\Validation\ValidationException;

class RebuildRecordSearchTextAction
{
    public function __construct(private readonly StartRecordBackfillAction $startRecordBackfill) {}

    /**
     * @throws ValidationException
     * @throws TenantUnderMaintenanceException
     */
    public function execute(ObjectType $objectType): void
    {
        if (!$objectType->isGeneric() || !$this->holdsRecords($objectType)) {
            return;
        }

        $this->startRecordBackfill->execute(
            (string) $objectType->tenant_id,
            RecordBackfillKind::SearchText,
            (string) $objectType->getKey(),
        );
    }

    private function holdsRecords(ObjectType $objectType): bool
    {
        return CustomRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $objectType->tenant_id)
            ->where('object_type_id', $objectType->getKey())
            ->exists();
    }
}
