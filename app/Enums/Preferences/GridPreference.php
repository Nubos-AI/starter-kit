<?php

declare(strict_types=1);

namespace App\Enums\Preferences;

enum GridPreference: string
{
    case ColumnState = 'columnState';

    public function category(): PreferenceCategory
    {
        return PreferenceCategory::ColumnsAndSorting;
    }
}
