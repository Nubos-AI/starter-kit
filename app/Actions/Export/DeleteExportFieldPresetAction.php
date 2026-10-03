<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Models\ExportFieldPreset;

class DeleteExportFieldPresetAction
{
    public function execute(ExportFieldPreset $preset): void
    {
        $preset->delete();
    }
}
