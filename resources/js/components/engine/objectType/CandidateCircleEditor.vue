<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/composables/useI18n';
import type {
    CandidateCircleValue,
    CandidateSourceKey,
} from '@/types/approvalDefinitions';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const ROLES_LABEL = t(
    'i18n.components.engine.object_type.candidate_circle_editor.roles',
);

const TEAMS_LABEL = t(
    'i18n.components.engine.object_type.candidate_circle_editor.teams',
);

const RECORD_TEAM_LABEL = t(
    'i18n.components.engine.object_type.candidate_circle_editor.record_s_team',
);

const RECORD_TEAM_HINT = t(
    'i18n.components.engine.object_type.candidate_circle_editor.the_team_assigned_to_the_record_is_also_included',
);

const FIELD_LABEL = t(
    'i18n.components.engine.object_type.candidate_circle_editor.field',
);

const FIELD_HINT = t(
    'i18n.components.engine.object_type.candidate_circle_editor.the_person_stored_in_the_selected_field_is_included',
);

const FIELD_CLEAR_LABEL = t(
    'i18n.components.engine.object_type.candidate_circle_editor.remove_field',
);

const USERS_LABEL = t(
    'i18n.components.engine.object_type.candidate_circle_editor.specific_people',
);

const props = withDefaults(
    defineProps<{
        roleOptions: SelectOption[];
        teamOptions: SelectOption[];
        userOptions: SelectOption[];
        fieldOptions: SelectOption[];
        anchorMode?: boolean;
    }>(),
    {
        anchorMode: false,
    },
);

const model = defineModel<CandidateCircleValue>({ required: true });

const controlId = useId();

const roleIds = computed<string[]>({
    get: () => model.value.role_ids,
    set: (value: string[]): void => patch({ role_ids: value }),
});

const teamIds = computed<string[]>({
    get: () => model.value.team_ids,
    set: (value: string[]): void => patch({ team_ids: value }),
});

const userIds = computed<string[]>({
    get: () => model.value.user_ids,
    set: (value: string[]): void => patch({ user_ids: value }),
});

const includeRecordTeam = computed<boolean>({
    get: () => model.value.include_record_team,
    set: (value: boolean): void => patch({ include_record_team: value }),
});

const fieldKey = computed<string>({
    get: () => model.value.field_key ?? '',
    set: (value: string): void =>
        patch({ field_key: value === '' ? null : value }),
});

function patch(changes: Partial<CandidateCircleValue>): void {
    const next: CandidateCircleValue = { ...model.value, ...changes };

    model.value = { ...next, sources: derivedSources(next) };
}

function derivedSources(circle: CandidateCircleValue): CandidateSourceKey[] {
    const sources: CandidateSourceKey[] = [];

    if (circle.role_ids.length > 0) {
        sources.push('role');
    }

    if (circle.team_ids.length > 0 || circle.include_record_team) {
        sources.push('team');
    }

    if (circle.field_key !== null) {
        sources.push('field');
    }

    if (circle.user_ids.length > 0) {
        sources.push('fixed_list');
    }

    return sources;
}
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
            <Label :for="`${controlId}_roles`">{{ ROLES_LABEL }}</Label>
            <MultiSelect
                :id="`${controlId}_roles`"
                v-model="roleIds"
                :options="props.roleOptions"
                :placeholder="
                    t(
                        'i18n.components.engine.object_type.candidate_circle_editor.select_roles',
                    )
                "
            />
        </div>

        <div class="grid gap-2">
            <Label :for="`${controlId}_teams`">{{ TEAMS_LABEL }}</Label>
            <MultiSelect
                :id="`${controlId}_teams`"
                v-model="teamIds"
                :options="props.teamOptions"
                :placeholder="
                    t(
                        'i18n.components.engine.object_type.candidate_circle_editor.select_teams',
                    )
                "
            />
        </div>

        <div v-if="!props.anchorMode" class="grid gap-2 sm:col-span-2">
            <div class="flex items-center gap-3">
                <Label :for="`${controlId}_record_team`">
                    {{ RECORD_TEAM_LABEL }}
                </Label>
                <Switch
                    :id="`${controlId}_record_team`"
                    v-model="includeRecordTeam"
                    data-candidate-record-team
                />
            </div>
            <p class="text-xs text-muted-foreground">{{ RECORD_TEAM_HINT }}</p>
        </div>

        <div v-if="!props.anchorMode" class="grid gap-2">
            <Label :for="`${controlId}_field`">{{ FIELD_LABEL }}</Label>
            <div class="flex items-center gap-2">
                <Select v-model="fieldKey">
                    <SelectTrigger :id="`${controlId}_field`" class="w-full">
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.components.engine.object_type.candidate_circle_editor.select_field',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in props.fieldOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    v-if="model.field_key !== null"
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="FIELD_CLEAR_LABEL"
                    @click="fieldKey = ''"
                >
                    <X />
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">{{ FIELD_HINT }}</p>
        </div>

        <div class="grid gap-2">
            <Label :for="`${controlId}_users`">{{ USERS_LABEL }}</Label>
            <MultiSelect
                :id="`${controlId}_users`"
                v-model="userIds"
                :options="props.userOptions"
                :placeholder="
                    t(
                        'i18n.components.engine.object_type.candidate_circle_editor.select_people',
                    )
                "
            />
        </div>
    </div>
</template>
