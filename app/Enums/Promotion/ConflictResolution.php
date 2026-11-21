<?php

declare(strict_types=1);

namespace App\Enums\Promotion;

enum ConflictResolution: string
{
    case TakeSource = 'take_source';

    case KeepTarget = 'keep_target';

    public function label(): string
    {
        return match ($this) {
            self::TakeSource => __('i18n.backend.enums.promotion.conflict_resolution.use_source'),
            self::KeepTarget => __('i18n.backend.enums.promotion.conflict_resolution.keep_live'),
        };
    }
}
