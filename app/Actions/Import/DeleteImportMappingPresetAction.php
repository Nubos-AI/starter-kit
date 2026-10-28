<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Models\ImportMappingPreset;

class DeleteImportMappingPresetAction
{
    public function execute(ImportMappingPreset $preset): void
    {
        $preset->delete();
    }
}
