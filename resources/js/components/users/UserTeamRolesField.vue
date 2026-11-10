<script setup lang="ts">
import { Plus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { DeleteButton } from '@/components/ui/icon-action-button';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type { SelectOption } from '@/types/ui';
import type { TeamRoleDraft } from '@/types/users';

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
    rows.value = rows.value.map((row, position) =>
        position === index ? { ...row, ...patch } : row,
    );
}
</script>

<template>
    <div class="flex flex-col gap-3" data-team-roles>
        <p
            v-if="rows.length === 0"
            class="text-sm text-muted-foreground"
            data-team-roles-empty
        >
            {{
                t(
                    'i18n.components.users.user_team_roles_field.this_user_holds_no_role_for_a_single_team',
                )
            }}
        </p>

        <div
            v-for="(row, index) in rows"
            :key="index"
            class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
            data-team-role-row
        >
            <div class="grid gap-2">
                <Label :for="`team-role-${index}-role`">{{
                    t('i18n.components.users.user_team_roles_field.role')
                }}</Label>
                <Combobox
                    :id="`team-role-${index}-role`"
                    :model-value="row.role_id"
                    :options="roleOptions"
                    :disabled="disabled"
                    :searchable="roleOptions.length > 8"
                    :placeholder="
                        t(
                            'i18n.components.users.user_team_roles_field.select_role',
                        )
                    "
                    data-team-role-role
                    @update:model-value="
                        (value: string | null) =>
                            updateRow(index, { role_id: value })
                    "
                />
            </div>

            <div class="grid gap-2">
                <Label :for="`team-role-${index}-team`">{{
                    t('i18n.components.users.user_team_roles_field.team')
                }}</Label>
                <Combobox
                    :id="`team-role-${index}-team`"
                    :model-value="row.team_id"
                    :options="teamOptions"
                    :disabled="disabled"
                    :searchable="teamOptions.length > 8"
                    :placeholder="
                        t(
                            'i18n.components.users.user_team_roles_field.select_team',
                        )
                    "
                    data-team-role-team
                    @update:model-value="
                        (value: string | null) =>
                            updateRow(index, { team_id: value })
                    "
                />
            </div>

            <DeleteButton
                :label="
                    t(
                        'i18n.components.users.user_team_roles_field.remove_team_role',
                    )
                "
                :disabled="disabled"
                test-id="team-role-remove"
                @click="removeRow(index)"
            />
        </div>

        <InputError :message="error" />

        <div>
            <Button
                type="button"
                variant="outline"
                :disabled="disabled"
                data-team-role-add
                @click="addRow"
            >
                <Plus />
                {{
                    t(
                        'i18n.components.users.user_team_roles_field.add_team_role',
                    )
                }}
            </Button>
        </div>
    </div>
</template>
