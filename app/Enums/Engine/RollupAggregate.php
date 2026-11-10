<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum RollupAggregate: string
{
    case Sum = 'sum';

    case Count = 'count';

    case Avg = 'avg';

    case Min = 'min';

    case Max = 'max';

    public function readsSourceField(): bool
    {
        return $this !== self::Count;
    }
}
