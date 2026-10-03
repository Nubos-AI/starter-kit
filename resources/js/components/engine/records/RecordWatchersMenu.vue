<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import RecordPeopleTrigger from '@/components/engine/records/RecordPeopleTrigger.vue';
import { MultiSelect } from '@/components/ui/multi-select';
import { useI18n } from '@/composables/useI18n';
import { useWatchers } from '@/composables/useWatchers';
import type { WatcherItem } from '@/composables/useWatchers';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const EMPTY_LABEL = t(
    'i18n.components.engine.records.record_watchers_menu.no_watchers',
);

const UNKNOWN_WATCHER_LABEL = t(
    'i18n.components.engine.records.record_watchers_menu.unknown',
);

const props = withDefaults(
    defineProps<{
        recordId: string;
        options: SelectOption[];
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);

const MANUAL_SOURCE = 'manual';

const { items, loadForRecord, syncWatchers } = useWatchers();

const manualWatchers = computed<WatcherItem[]>(() =>
    items.value.filter((item) => item.source === MANUAL_SOURCE),
);

const watcherIds = computed<string[]>(() =>
    manualWatchers.value
        .map((item) => item.user?.id ?? null)
        .filter((id): id is string => id !== null),
);

const selectedIds = ref<string[]>([...watcherIds.value]);

watch(watcherIds, (ids) => {
    selectedIds.value = [...ids];
});

function displayLabel(item: WatcherItem): string {
    return item.user?.label ?? UNKNOWN_WATCHER_LABEL;
}

const choices = computed<SelectOption[]>(() => {
    const known = new Set(props.options.map((option) => option.value));

    const missing = manualWatchers.value
        .filter((item) => item.user !== null && !known.has(item.user.id))
        .map((item) => ({
            value: item.user?.id ?? '',
            label: displayLabel(item),
            avatar: { name: displayLabel(item) },
        }));

    return [...props.options, ...missing];
});

const label = computed<string>(() => {
    const count = manualWatchers.value.length;

    if (count === 0) {
        return EMPTY_LABEL;
    }

    if (count === 1) {
        return displayLabel(manualWatchers.value[0]);
    }

    return t('i18n.components.engine.records.record_watchers_menu.watchers_2', {
        value1: count,
    });
});

const avatarName = computed<string | null>(() =>
    manualWatchers.value.length === 1
        ? displayLabel(manualWatchers.value[0])
        : null,
);

async function onSelectionChanged(ids: string[]): Promise<void> {
    const saved = await syncWatchers(props.recordId, ids);

    if (!saved) {
        selectedIds.value = [...watcherIds.value];
    }
}

onMounted(() => {
    void loadForRecord(props.recordId);
});
</script>

<template>
    <MultiSelect
        v-model="selectedIds"
        :options="choices"
        :disabled="props.disabled"
        :placeholder="
            t(
                'i18n.components.engine.records.record_watchers_menu.select_people',
            )
        "
        :search-placeholder="
            t(
                'i18n.components.engine.records.record_watchers_menu.search_people',
            )
        "
        :empty-label="
            t(
                'i18n.components.engine.records.record_watchers_menu.there_are_no_people_to_select',
            )
        "
        :aria-label="
            t('i18n.components.engine.records.record_watchers_menu.watchers')
        "
        data-record-watchers-menu
        @update:model-value="onSelectionChanged"
    >
        <template #trigger>
            <RecordPeopleTrigger
                caption="Beobachter"
                :label="label"
                :avatar-name="avatarName"
                :disabled="props.disabled"
            />
        </template>
    </MultiSelect>
</template>
