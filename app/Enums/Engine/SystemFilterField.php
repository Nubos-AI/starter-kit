<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum SystemFilterField: string
{
    case Owner = 'owner_id';

    case Team = 'team_id';

    case Pipeline = 'pipeline_id';

    case Stage = 'stage_id';

    case AgingAge = 'aging_age';

    case AgingStage = 'aging_stage';
}
