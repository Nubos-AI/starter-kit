<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronsUpDown, UsersRound } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { dashboard } from '@/routes';
import type { TeamSummary } from '@/types/teams';

const { t } = useI18n();

const page = usePage();

const currentTeam = computed<TeamSummary | null>(
    () => page.props?.currentTeam ?? null,
);

const availableTeams = computed<TeamSummary[]>(
    () => page.props?.availableTeams ?? [],
);

const triggerLabel = computed<string>(
    () =>
        currentTeam.value?.name ??
        t('i18n.components.teams.team_switcher.no_team'),
);

function isLeadingSegment(segment: string | undefined): boolean {
    if (segment === undefined) {
        return false;
    }

    return availableTeams.value.some(
        (team) => team.id === segment || team.slug === segment,
    );
}

function urlForTeam(teamId: string): string {
    const [path, query = ''] = (page.url ?? '/').split('?');
    const segments = path.split('/').filter((segment) => segment !== '');

    if (!isLeadingSegment(segments[0])) {
        return dashboard.url({ activeTeam: teamId });
    }

    segments.shift();

    const swapped = `/${[teamId, ...segments].join('/')}`;

    return query === '' ? swapped : `${swapped}?${query}`;
}

function switchTo(team: TeamSummary): void {
    if (team.id === currentTeam.value?.id) {
        return;
    }

    router.visit(urlForTeam(team.id));
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                class="gap-2"
                :aria-label="
                    t('i18n.components.teams.team_switcher.switch_team')
                "
            >
                <UsersRound />
                <span class="max-w-32 truncate">{{ triggerLabel }}</span>
                <ChevronsUpDown class="opacity-60" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            extension-point="menus.team-switcher"
            :extension-context="$props"
            align="end"
            class="w-56"
        >
            <DropdownMenuLabel>{{
                t('i18n.components.teams.team_switcher.switch_team')
            }}</DropdownMenuLabel>
            <DropdownMenuSeparator />

            <p
                v-if="availableTeams.length === 0"
                class="px-2 py-1.5 text-muted-foreground"
            >
                {{
                    t(
                        'i18n.components.teams.team_switcher.you_do_not_belong_to_any_team',
                    )
                }}
            </p>

            <DropdownMenuItem
                v-for="team in availableTeams"
                :key="team.id"
                data-team-option
                :disabled="team.id === currentTeam?.id"
                @select="switchTo(team)"
            >
                {{ team.name }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
