<?php

declare(strict_types=1);

namespace App\Enums\Import;

enum ImportDuplicateMode: string
{
    case Skip = 'skip';

    case Upsert = 'upsert';

    case Insert = 'insert';
}
