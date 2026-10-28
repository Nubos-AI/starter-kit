<?php

declare(strict_types=1);

namespace App\Enums\Preferences;

enum PreferenceScope: string
{
    case Settings = 'settings';

    case ObjectTypes = 'objectTypes';

    case Grids = 'grids';

    public function area(): PreferenceArea
    {
        return match ($this) {
            self::Settings => PreferenceArea::Global,
            self::ObjectTypes => PreferenceArea::Records,
            self::Grids => PreferenceArea::Configuration,
        };
    }

    public function isKeyed(): bool
    {
        return $this !== self::Settings;
    }
}
