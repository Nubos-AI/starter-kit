<?php

declare(strict_types=1);

namespace App\Exceptions\Promotion;

use RuntimeException;

class PromotionNotExecutableException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function notApproved(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_not_executable_exception.this_promotion_has_not_been_approved_or_is_already'));
    }

    public static function actingUserOutsideTenant(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_not_executable_exception.the_initiating_user_does_not_belong_to_this_promotion'));
    }

    public static function triggerWithoutAuthority(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_not_executable_exception.the_initiating_user_no_longer_has_permission_to_promote'));
    }

    /**
     * @param  list<string>  $undecided
     * @param  list<string>  $outdated
     */
    public static function conflictsChanged(array $undecided, array $outdated): self
    {
        $details = [];

        if ($undecided !== []) {
            $undecidedList = implode(', ', $undecided);
            $details[] = __('i18n.backend.exceptions.promotion.promotion_not_executable_exception.new_and_unresolved_conflicts', ['value1' => $undecidedList]);
        }

        if ($outdated !== []) {
            $outdatedList = implode(', ', $outdated);
            $details[] = __('i18n.backend.exceptions.promotion.promotion_not_executable_exception.resolved_but_no_longer_conflicting', ['value1' => $outdatedList]);
        }

        $detailList = implode('; ', $details);

        return new self(__('i18n.backend.exceptions.promotion.promotion_not_executable_exception.the_conflicts_of_this_promotion_have_changed_since_approval', ['value1' => $detailList]));
    }
}
