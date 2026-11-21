<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum CascadeBehavior: string
{
    case Cascade = 'cascade';

    case Restrict = 'restrict';

    case Nullify = 'nullify';

    public function label(): string
    {
        return match ($this) {
            self::Cascade => __('i18n.backend.enums.engine.cascade_behavior.delete_related_records'),
            self::Restrict => __('i18n.backend.enums.engine.cascade_behavior.prevent_deletion'),
            self::Nullify => __('i18n.backend.enums.engine.cascade_behavior.remove_relationship'),
        };
    }
}
