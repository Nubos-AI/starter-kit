<?php

declare(strict_types=1);

namespace App\Exceptions\Promotion;

use RuntimeException;

class PromotionNotRollbackableException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyRolledBack(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_not_rollbackable_exception.this_promotion_has_already_been_reverted'));
    }

    public static function notExecuted(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_not_rollbackable_exception.only_a_completed_or_failed_promotion_can_be_reverted'));
    }

    public static function withoutSnapshotGroup(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_not_rollbackable_exception.there_is_no_saved_previous_state_for_this_promotion'));
    }
}
