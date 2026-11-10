<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\BulkActionData;

interface BulkDispatcherInterface
{
    public function start(BulkActionData $input): void;
}
