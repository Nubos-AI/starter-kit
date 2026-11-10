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
    deniedPermissionIds: string[];
    permissionOptions: SelectOption[];
}>();

useTeamSection(props.team, 'deniedPermissions');
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
            field="denied_permission_ids"
            :selected="props.deniedPermissionIds"
            :options="props.permissionOptions"
            :title="t('i18n.pages.teams.form.explicitly_denied_permissions')"
            :description="
                t(
                    'i18n.pages.teams.form.a_denial_here_overrides_this_team_s_roles_a',
                )
            "
            :label="t('i18n.pages.teams.form.denied_permissions')"
            :placeholder="t('i18n.pages.teams.form.select_permissions')"
            :empty-label="t('i18n.pages.teams.form.there_are_no_permissions')"
        />
    </div>
</template>
