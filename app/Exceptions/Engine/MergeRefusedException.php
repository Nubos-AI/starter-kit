<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use App\DTOs\Engine\MergeBlocker;
use RuntimeException;

class MergeRefusedException extends RuntimeException
{
    /**
     * @param  list<MergeBlocker>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(__('i18n.backend.exceptions.engine.merge_refused_exception.merging_was_denied').implode(', ', array_map(
            static fn (MergeBlocker $blocker): string => $blocker->reason->value,
            $blockers,
        )).'.');
    }

    /**
     * @return list<array{reason: string, detail: string|null}>
     */
    public function payload(): array
    {
        return array_map(
            static fn (MergeBlocker $blocker): array => [
                'reason' => $blocker->reason->value,
                'detail' => $blocker->detail,
            ],
            $this->blockers,
        );
    }
}
