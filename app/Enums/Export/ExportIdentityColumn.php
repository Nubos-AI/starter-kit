<?php

declare(strict_types=1);

namespace App\Enums\Export;

enum ExportIdentityColumn: string
{
    case ExternalReferenceId = 'external_reference_id';

    case RecordNumber = 'record_number';

    public function label(): string
    {
        return match ($this) {
            self::ExternalReferenceId => __('i18n.backend.enums.export.export_identity_column.external_reference_id'),
            self::RecordNumber => __('i18n.backend.enums.export.export_identity_column.record_number'),
        };
    }
}
