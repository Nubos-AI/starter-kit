<?php

declare(strict_types=1);

namespace App\Enums\Preferences;

enum GlobalPreference: string
{
    case Appearance = 'appearance';

    case Density = 'density';

    case PageSize = 'pageSize';

    case SidebarOpen = 'sidebarOpen';

    case StartObjectTypeId = 'startObjectTypeId';

    public function category(): PreferenceCategory
    {
        return PreferenceCategory::LayoutAndAppearance;
    }
}
