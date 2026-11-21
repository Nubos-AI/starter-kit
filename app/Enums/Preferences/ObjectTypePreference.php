<?php

declare(strict_types=1);

namespace App\Enums\Preferences;

enum ObjectTypePreference: string
{
    case ViewMode = 'viewMode';

    case HierarchyOrder = 'hierarchyOrder';

    case ColumnState = 'columnState';

    case LastSegmentId = 'lastSegmentId';

    case LastFilter = 'lastFilter';

    case CollapsedSections = 'collapsedSections';

    case HiddenSections = 'hiddenSections';

    public function category(): PreferenceCategory
    {
        return match ($this) {
            self::ViewMode, self::HierarchyOrder => PreferenceCategory::ViewMode,
            self::ColumnState => PreferenceCategory::ColumnsAndSorting,
            self::LastSegmentId, self::LastFilter => PreferenceCategory::FilterAndSegment,
            self::CollapsedSections => PreferenceCategory::PanelState,
            self::HiddenSections => PreferenceCategory::PanelVisibility,
        };
    }
}
