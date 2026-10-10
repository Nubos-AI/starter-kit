<?php

declare(strict_types=1);

namespace App\Enums\Import;

enum ImportIdentityTarget: string
{
    case ExternalReferenceId = 'external_reference_id';

    case RecordNumber = 'record_number';

    public function label(): string
    {
        return match ($this) {
            self::ExternalReferenceId => __('i18n.backend.enums.import.import_identity_target.external_reference_id'),
            self::RecordNumber => __('i18n.backend.enums.import.import_identity_target.record_number'),
        };
    }

    public function column(): string
    {
        return $this->value;
    }
}
