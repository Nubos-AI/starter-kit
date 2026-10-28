<script setup lang="ts">
import { computed } from 'vue';
import RecordPeopleTrigger from '@/components/engine/records/RecordPeopleTrigger.vue';
import { MultiSelect } from '@/components/ui/multi-select';
import { useI18n } from '@/composables/useI18n';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const EMPTY_LABEL = t(
    'i18n.components.engine.records.record_collaborators_menu.no_contributors',
);

const props = withDefaults(
    defineProps<{
        options: SelectOption[];
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);

const collaboratorIds = defineModel<string[]>({ required: true });

const selected = computed<SelectOption[]>(() =>
    props.options.filter((option) =>
        collaboratorIds.value.includes(option.value),
    ),
);

const label = computed<string>(() => {
    const count = collaboratorIds.value.length;

    if (count === 0) {
        return EMPTY_LABEL;
    }

    if (count === 1) {
        return (
            selected.value[0]?.label ??
            t(
                'i18n.components.engine.records.record_collaborators_menu.1_contributor',
            )
        );
    }

    return t(
        'i18n.components.engine.records.record_collaborators_menu.collaborators',
        { value1: count },
    );
});

const avatarName = computed<string | null>(() =>
    collaboratorIds.value.length === 1
        ? (selected.value[0]?.avatar?.name ?? null)
        : null,
);
</script>

<template>
    <MultiSelect
        v-model="collaboratorIds"
        :options="props.options"
        :disabled="props.disabled"
        :placeholder="
            t(
                'i18n.components.engine.records.record_collaborators_menu.select_people',
            )
        "
        :search-placeholder="
            t(
                'i18n.components.engine.records.record_collaborators_menu.search_people',
            )
        "
        :empty-label="
            t(
                'i18n.components.engine.records.record_collaborators_menu.there_are_no_people_to_select',
            )
        "
        :aria-label="
            t(
                'i18n.components.engine.records.record_collaborators_menu.contributors',
            )
        "
        data-record-collaborators-menu
    >
        <template #trigger>
            <RecordPeopleTrigger
                caption="Mitwirkende"
                :label="label"
                :avatar-name="avatarName"
                :disabled="props.disabled"
            />
        </template>
    </MultiSelect>
</template>
