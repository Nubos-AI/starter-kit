<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum MergeUndoRefusalReason: string
{
    case AlreadyUndone = 'already_undone';

    case WindowExpired = 'window_expired';

    case IdentifierTaken = 'identifier_taken';

    case TargetGone = 'target_gone';
}
