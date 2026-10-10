<?php

declare(strict_types=1);

namespace App\Exceptions\Approvals;

use RuntimeException;
use Throwable;

class RecordBoundCandidateCircleException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function forStage(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.approvals.record_bound_candidate_circle_exception.the_approvers_for_stage_are_derived_from_a_record', ['value1' => $position]),
        );
    }
}
