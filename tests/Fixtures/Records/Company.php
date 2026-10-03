<?php

declare(strict_types=1);

namespace Tests\Fixtures\Records;

use App\Models\Abstracts\TypedRecord;

class Company extends TypedRecord
{
    public static function objectTypeSlug(): string
    {
        return 'companies';
    }
}
