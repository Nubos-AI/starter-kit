<script setup lang="ts">
import { computed, toRef } from 'vue';
import { Combobox } from '@/components/ui/combobox';
import { useI18n } from '@/composables/useI18n';
import { useRecordLookup } from '@/composables/useRecordLookup';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        objectTypeSlug: string | null;
        id?: string;
        placeholder?: string;
        ariaLabel?: string;
        disabled?: boolean;
    }>(),
    {
        id: undefined,
        placeholder: undefined,
        ariaLabel: undefined,
        disabled: false,
    },
);

const model = defineModel<string | null>({ required: true });

const { options, loading, search, loadMore } = useRecordLookup(
    toRef(props, 'objectTypeSlug'),
);

const withCurrentSelection = computed<SelectOption[]>(() => {
    const selected = model.value;

    if (
        selected === null ||
        selected === '' ||
        options.value.some((option) => option.value === selected)
    ) {
        return options.value;
    }

    return [{ value: selected, label: selected }, ...options.value];
});

const emptyLabel = computed<string>(() =>
    loading.value
        ? t('i18n.components.engine.record_picker.loading')
        : t('i18n.components.engine.record_picker.no_records_found'),
);
</script>

<template>
    <Combobox
        v-model="model"
        :id="props.id"
        :options="withCurrentSelection"
        :placeholder="
            props.placeholder ??
            t('i18n.components.engine.record_picker.search_records')
        "
        :aria-label="props.ariaLabel"
        :disabled="props.disabled || props.objectTypeSlug === null"
        :empty-label="emptyLabel"
        no-results-label="Kein Treffer"
        :search-placeholder="
            t('i18n.components.engine.record_picker.search_by_name_or_number')
        "
        server-search
        @search="search = $event"
        @load-more="loadMore"
    />
</template>
