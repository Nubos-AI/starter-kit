<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Handlers\CustomFields\ComputedFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use JsonException;

class ComputedFieldWriter
{
    public function __construct(
        private readonly ComputedFieldOrderResolver $orderResolver,
        private readonly ComputedFieldHandler $handler,
    ) {}

    /**
     * @param  list<string>  $changedFieldKeys
     * @return list<FieldDefinition>
     */
    public function orderFor(string $objectTypeId, array $changedFieldKeys = []): array
    {
        return $this->orderResolver->orderFor($objectTypeId, $changedFieldKeys);
    }

    /**
     * @throws JsonException
     */
    public function write(FieldDefinition $field, CustomRecord $record): void
    {
        $this->handler->materialize($field, $record);
        $record->refresh();
    }

    /**
     * @param  list<string>  $changedFieldKeys
     * @return list<string>
     *
     * @throws JsonException
     */
    public function materialize(CustomRecord $record, array $changedFieldKeys = []): array
    {
        /** @var list<string> $written */
        $written = [];

        foreach ($this->orderFor($record->object_type_id, $changedFieldKeys) as $field) {
            $this->write($field, $record);
            $written[] = $field->key;
        }

        return $written;
    }
}
