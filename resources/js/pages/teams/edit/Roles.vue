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
    roleIds: string[];
    roleOptions: SelectOption[];
}>();

useTeamSection(props.team, 'roles');
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
            field="role_ids"
            :selected="props.roleIds"
            :options="props.roleOptions"
            :title="t('i18n.pages.teams.form.roles')"
            :description="
                t(
                    'i18n.pages.teams.form.these_roles_apply_to_each_member_while_working_in',
                )
            "
            :label="t('i18n.pages.teams.form.assigned_roles')"
            :placeholder="t('i18n.pages.teams.form.select_roles')"
            :empty-label="
                t('i18n.pages.teams.form.there_are_no_assignable_roles')
            "
        />
    </div>
</template>
