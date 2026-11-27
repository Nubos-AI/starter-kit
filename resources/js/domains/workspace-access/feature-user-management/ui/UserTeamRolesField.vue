<script setup lang="ts">
import { Plus } from '@lucide/vue';
import type { TeamRoleDraft } from '@/domains/workspace-access/data';
import { useI18n } from '@/shared/composables/useI18n';
import type { SelectOption } from '@/shared/types/ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Combobox } from '@/shared/ui/primitives/combobox';
import RemoveButton from '@/shared/ui/RemoveButton.vue';

const { t } = useI18n();

defineProps<{
  roleOptions: SelectOption[];
  teamOptions: SelectOption[];
  disabled: boolean;
  error?: string;
}>();

const rows = defineModel<TeamRoleDraft[]>({ required: true });

function addRow(): void {
  rows.value = [...rows.value, { role_id: null, team_id: null }];
}

function removeRow(index: number): void {
  rows.value = rows.value.filter((_, position) => position !== index);
}

function updateRow(index: number, patch: Partial<TeamRoleDraft>): void {
  rows.value = rows.value.map((row, position) => (position === index ? { ...row, ...patch } : row));
}
</script>

<template>
  <div class="flex flex-col gap-3" data-team-roles>
    <EmptyState
      v-if="rows.length === 0"
      data-team-roles-empty
      variant="inline"
      :description="t('i18n.components.users.user_team_roles_field.this_user_holds_no_role_for_a_single_team')"
    />

    <div
      v-for="(row, index) in rows"
      :key="index"
      class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
      data-team-role-row
    >
      <FormField :for="`team-role-${index}-role`" :label="t('i18n.components.users.user_team_roles_field.role')">
        <Combobox
          :id="`team-role-${index}-role`"
          :model-value="row.role_id"
          :options="roleOptions"
          :disabled="disabled"
          :searchable="roleOptions.length > 8"
          :placeholder="t('i18n.components.users.user_team_roles_field.select_role')"
          data-team-role-role
          @update:model-value="(value: string | null) => updateRow(index, { role_id: value })"
        />
      </FormField>

      <FormField :for="`team-role-${index}-team`" :label="t('i18n.components.users.user_team_roles_field.team')">
        <Combobox
          :id="`team-role-${index}-team`"
          :model-value="row.team_id"
          :options="teamOptions"
          :disabled="disabled"
          :searchable="teamOptions.length > 8"
          :placeholder="t('i18n.components.users.user_team_roles_field.select_team')"
          data-team-role-team
          @update:model-value="(value: string | null) => updateRow(index, { team_id: value })"
        />
      </FormField>

      <RemoveButton
        :label="t('i18n.components.users.user_team_roles_field.remove_team_role')"
        :disabled="disabled"
        data-testid="team-role-remove"
        @click="removeRow(index)"
      />
    </div>

    <InputError :message="error" />

    <div>
      <Button type="button" variant="outline" :disabled="disabled" data-team-role-add @click="addRow">
        <Plus />
        {{ t('i18n.components.users.user_team_roles_field.add_team_role') }}
      </Button>
    </div>
  </div>
</template>
