<?php

declare(strict_types=1);

namespace App\Contracts\Export;

use App\Models\ExportJob;

interface ExportDispatcherInterface
{
    public function start(ExportJob $exportJob): void;
}
