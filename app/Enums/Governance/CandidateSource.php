<?php

declare(strict_types=1);

namespace App\Enums\Governance;

enum CandidateSource: string
{
    case Role = 'role';

    case Team = 'team';

    case Field = 'field';

    case FixedList = 'fixed_list';
}
