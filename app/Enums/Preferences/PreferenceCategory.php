<?php

declare(strict_types=1);

namespace App\Enums\Preferences;

enum PreferenceCategory: string
{
    case ColumnsAndSorting = 'columnsAndSorting';

    case ViewMode = 'viewMode';

    case FilterAndSegment = 'filterAndSegment';

    case LayoutAndAppearance = 'layoutAndAppearance';

    case PanelState = 'panelState';

    case PanelVisibility = 'panelVisibility';

    public function label(): string
    {
        return match ($this) {
            self::ColumnsAndSorting => __('i18n.backend.enums.preferences.preference_category.columns_sorting'),
            self::ViewMode => __('i18n.backend.enums.preferences.preference_category.view_mode'),
            self::FilterAndSegment => __('i18n.backend.enums.preferences.preference_category.filter_segment'),
            self::LayoutAndAppearance => __('i18n.backend.enums.preferences.preference_category.layout_appearance'),
            self::PanelState => __('i18n.backend.enums.preferences.preference_category.expanded_and_collapsed_sections'),
            self::PanelVisibility => __('i18n.backend.enums.preferences.preference_category.hidden_panels'),
        };
    }

    /**
     * @return array<int, PreferenceArea>
     */
    public function areas(): array
    {
        return match ($this) {
            self::ColumnsAndSorting => [PreferenceArea::Records, PreferenceArea::Configuration],
            self::ViewMode, self::FilterAndSegment, self::PanelState, self::PanelVisibility => [PreferenceArea::Records],
            self::LayoutAndAppearance => [PreferenceArea::Global],
        };
    }

    public function defaultEnabled(): bool
    {
        return $this !== self::FilterAndSegment;
    }
}
