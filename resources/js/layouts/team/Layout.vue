<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import SectionNav from '@/components/SectionNav.vue';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { teamSectionItems } from '@/lib/teamSections';
import type { SectionNavEntry } from '@/types/navigation';
import type { TeamSummary } from '@/types/teams';

const { t } = useI18n();
const { can } = usePermissions();

const page = usePage();

function readTeam(value: unknown): TeamSummary | null {
    if (
        typeof value !== 'object' ||
        value === null ||
        !('id' in value) ||
        !('name' in value) ||
        typeof value.id !== 'string' ||
        typeof value.name !== 'string'
    ) {
        return null;
    }

    return { id: value.id, name: value.name };
}

const team = computed<TeamSummary | null>(() => readTeam(page.props.team));

const isCreating = computed<boolean>(() => page.props.mode === 'create');

const sections = computed<SectionNavEntry[]>(() => {
    if (team.value === null && !isCreating.value) {
        return [];
    }

    const mayManageAccess = can('teams.manage');

    return teamSectionItems(team.value?.id ?? null, t).map((item) => {
        const isAccessBlocked =
            item.key === 'accessRules' &&
            team.value !== null &&
            !mayManageAccess;

        return {
            key: item.key,
            title: item.title,
            href: isAccessBlocked ? null : item.href,
            disabledReason:
                item.href === null
                    ? t(
                          'i18n.layouts.team.layout.available_after_saving_the_details',
                      )
                    : isAccessBlocked
                      ? t(
                            'i18n.layouts.team.layout.requires_the_permission_to_manage_teams',
                        )
                      : null,
        };
    });
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-6 p-3">
        <Heading
            v-if="team !== null"
            variant="small"
            :title="team.name"
            :description="
                t(
                    'i18n.layouts.team.layout.manage_this_team_s_details_members_roles_and_access',
                )
            "
        />

        <Heading
            v-else-if="isCreating"
            variant="small"
            :title="t('i18n.layouts.team.layout.new_team')"
            :description="
                t(
                    'i18n.layouts.team.layout.members_roles_and_permissions_become_available_once_the',
                )
            "
        />

        <SectionNav
            :entries="sections"
            :label="t('i18n.layouts.team.layout.team')"
            data-team-sections
        >
            <slot />
        </SectionNav>
    </div>
</template>
