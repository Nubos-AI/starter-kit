<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\DTOs\Reports\ReportDefinitionData;
use Illuminate\Database\Query\Builder;

interface ReportQuerySourceInterface
{
    public function query(ReportDefinitionData $definition): ?Builder;
}
