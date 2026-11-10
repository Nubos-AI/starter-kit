<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TeamSelectionCard from '@/components/teams/TeamSelectionCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useTeamSection } from '@/composables/useTeamSection';
import type { TeamSummary } from '@/types/teams';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = defineProps<{
    team: TeamSummary;
    memberIds: string[];
    memberOptions: SelectOption[];
}>();

useTeamSection(props.team, 'members');
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head
            :title="
                t('i18n.pages.teams.form.edit', { value1: props.team.name })
            "
        />

        <TeamSelectionCard
            :team-id="props.team.id"
            field="member_ids"
            :selected="props.memberIds"
            :options="props.memberOptions"
            :title="t('i18n.pages.teams.form.members')"
            :description="
                t(
                    'i18n.pages.teams.form.team_members_can_select_it_in_the_team_switcher',
                )
            "
            :label="t('i18n.pages.teams.form.assigned_members')"
            :placeholder="t('i18n.pages.teams.form.select_members')"
            :empty-label="t('i18n.pages.teams.form.there_are_no_users')"
        />
    </div>
</template>
