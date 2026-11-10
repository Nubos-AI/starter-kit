import { usePage } from '@inertiajs/vue3';
import { setUrlDefaults } from '@/wayfinder';

export function activeTeamSegment(): string | undefined {
    const team = usePage().props?.currentTeam;

    return team?.id;
}

export function initializeTeamSegment(): void {
    setUrlDefaults(() => {
        const segment = activeTeamSegment();

        return segment ? { activeTeam: segment } : {};
    });
}
