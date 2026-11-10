<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;

class BulkExportWithoutRowsException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('i18n.backend.support.engine.bulk_chunk_runner.bulk_export_produced_no_visible_rows'));
    }
}
