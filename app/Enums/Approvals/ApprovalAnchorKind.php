<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

use App\Models\PromotionRun;

enum ApprovalAnchorKind: string
{
    case Promotion = 'promotion';

    public static function forModelClass(?string $modelClass): ?self
    {
        return match ($modelClass) {
            PromotionRun::class => self::Promotion,
            default => null,
        };
    }

    public function modelClass(): string
    {
        return match ($this) {
            self::Promotion => PromotionRun::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Promotion => __('i18n.backend.enums.approvals.approval_anchor_kind.promotion'),
        };
    }
}
