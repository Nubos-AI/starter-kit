<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\FieldDefinition;

class EncryptedFieldKeys
{
    /**
     * @return list<string>
     */
    public function forObjectType(string $objectTypeId): array
    {
        /** @var list<string> $keys */
        $keys = FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->where('is_encrypted', true)
            ->pluck('key')
            ->all();

        return $keys;
    }
}
