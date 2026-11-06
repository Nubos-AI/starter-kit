<?php

declare(strict_types=1);

namespace App\Support\Engine;

use Illuminate\Validation\ValidationException;

class FieldIndexingRules
{
    /**
     * @throws ValidationException
     */
    public function assertEncryptionIsCompatible(bool $isEncrypted, bool $isSortable, bool $isFilterable, bool $isUnique): void
    {
        if ($isEncrypted && ($isSortable || $isFilterable || $isUnique)) {
            throw ValidationException::withMessages([
                'is_encrypted' => __('i18n.backend.support.engine.field_indexing_rules.encrypted_fields_cannot_be_sort_filter_or_unique_indexed'),
            ]);
        }
    }
}
