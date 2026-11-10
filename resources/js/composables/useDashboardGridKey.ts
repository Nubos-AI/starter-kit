import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

export function useDashboardGridKey(
    dashboardId: () => string | null,
): ComputedRef<string> {
    const page = usePage();

    return computed<string>(
        () => `${page.props.currentTeam?.id ?? ''}:${dashboardId() ?? ''}`,
    );
}
