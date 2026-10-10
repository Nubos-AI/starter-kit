<?php

declare(strict_types=1);

namespace App\Enums\Ui;

enum NavIcon: string
{
    case LayoutGrid = 'layout-grid';

    case LayoutDashboard = 'layout-dashboard';

    case Clock = 'clock';

    case ChartColumn = 'chart-column';

    case Award = 'award';

    case Target = 'target';

    case Waypoints = 'waypoints';

    case Database = 'database';

    case Wrench = 'wrench';

    case Settings2 = 'settings2';

    case Filter = 'filter';

    case ShieldCheck = 'shield-check';

    case ShieldUser = 'shield-user';

    case UserCog = 'user-cog';

    case Users = 'users';

    case Webhook = 'webhook';

    case KeyRound = 'key-round';

    public function label(): string
    {
        return match ($this) {
            self::LayoutGrid => __('i18n.backend.enums.ui.nav_icon.tiles'),
            self::LayoutDashboard => __('i18n.backend.enums.ui.nav_icon.overview'),
            self::Clock => __('i18n.backend.enums.ui.nav_icon.clock'),
            self::ChartColumn => __('i18n.backend.enums.ui.nav_icon.chart'),
            self::Award => __('i18n.backend.enums.ui.nav_icon.award'),
            self::Target => __('i18n.backend.enums.ui.nav_icon.goal'),
            self::Waypoints => __('i18n.backend.enums.ui.nav_icon.branch'),
            self::Database => __('i18n.backend.enums.ui.nav_icon.database'),
            self::Wrench => __('i18n.backend.enums.ui.nav_icon.wrench'),
            self::Settings2 => __('i18n.backend.enums.ui.nav_icon.settings'),
            self::Filter => __('i18n.backend.enums.ui.nav_icon.filter'),
            self::ShieldCheck => __('i18n.backend.enums.ui.nav_icon.shield_with_checkmark'),
            self::ShieldUser => __('i18n.backend.enums.ui.nav_icon.shield_with_person'),
            self::UserCog => __('i18n.backend.enums.ui.nav_icon.person_with_gear'),
            self::Users => __('i18n.backend.enums.ui.nav_icon.people'),
            self::Webhook => __('i18n.backend.enums.ui.nav_icon.webhook'),
            self::KeyRound => __('i18n.backend.enums.ui.nav_icon.key'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $icon): array => ['value' => $icon->value, 'label' => $icon->label()],
            self::cases(),
        );
    }
}
