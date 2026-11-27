<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\Enums\Outbox\OutboxTopic;
use App\Models\FieldDefinition;
use Illuminate\Support\Str;

class FieldMaintenanceRequester
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function request(FieldDefinition $field, bool $syncIndexes, bool $backfillRollups): void
    {
        if (!$syncIndexes && !$backfillRollups) {
            return;
        }

        $this->outbox->record(
            OutboxTopic::FieldDefinitionChanged->value,
            $field->tenant_id,
            OutboxSubject::of($field),
            [
                'request_id' => (string) Str::ulid(),
                'sync_indexes' => $syncIndexes,
                'backfill_rollups' => $backfillRollups,
            ],
        );
    }
}
