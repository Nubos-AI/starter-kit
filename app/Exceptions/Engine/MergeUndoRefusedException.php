<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use App\Enums\Engine\MergeUndoRefusalReason;
use RuntimeException;

class MergeUndoRefusedException extends RuntimeException
{
    public function __construct(public readonly MergeUndoRefusalReason $reason)
    {
        parent::__construct(__('i18n.backend.exceptions.engine.merge_undo_refused_exception.the_merge_cannot_be_undone').$reason->value.'.');
    }
}
