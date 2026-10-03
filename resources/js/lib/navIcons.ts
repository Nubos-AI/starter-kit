import {
    Award,
    ChartColumn,
    Clock,
    Database,
    Filter,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    Settings2,
    ShieldCheck,
    ShieldUser,
    Target,
    UserCog,
    Users,
    Waypoints,
    Webhook,
    Wrench,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';

const NAV_ICONS: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    'layout-dashboard': LayoutDashboard,
    clock: Clock,
    'chart-column': ChartColumn,
    award: Award,
    target: Target,
    waypoints: Waypoints,
    database: Database,
    wrench: Wrench,
    settings2: Settings2,
    filter: Filter,
    'shield-check': ShieldCheck,
    'shield-user': ShieldUser,
    'user-cog': UserCog,
    users: Users,
    webhook: Webhook,
    'key-round': KeyRound,
};

export function iconFor(key?: string): LucideIcon | undefined {
    return key ? NAV_ICONS[key] : undefined;
}
