<?php

declare(strict_types=1);

namespace App\Enums\ConfigBundle;

enum ArtifactWriteAction: string
{
    case Created = 'created';

    case Updated = 'updated';

    case Removed = 'removed';

    case Skipped = 'skipped';
}
