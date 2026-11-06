<?php

declare(strict_types=1);

namespace App\Exceptions\Approvals;

use RuntimeException;

class UnsupportedApprovalAnchorException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forAnchorType(string $anchorType): self
    {
        return new self(__('i18n.backend.exceptions.approvals.unsupported_approval_anchor_exception.no_outcome_after_the_decision_is_configured_for_anchor', ['value1' => $anchorType]));
    }
}
