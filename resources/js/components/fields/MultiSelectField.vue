<script setup lang="ts">
import { computed } from 'vue';
import { MultiSelect } from '@/components/ui/multi-select';
import { normalizeFieldOptions } from '@/composables/useFieldTypeRegistry';
import { useI18n } from '@/composables/useI18n';
import type { FieldDefinition } from '@/types/fields';

const { t } = useI18n();

const SEARCH_THRESHOLD = 8;

const props = defineProps<{
    field: FieldDefinition;
    error?: string;
    describedBy?: string;
}>();

const model = defineModel<string[]>({ default: () => [] });

const options = computed(() =>
    normalizeFieldOptions(props.field.config?.options),
);
</script>

<template>
    <MultiSelect
        :id="field.key"
        v-model="model"
        :options="options"
        :searchable="options.length > SEARCH_THRESHOLD"
        :aria-label="field.label"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="describedBy"
        :placeholder="
            t('i18n.components.fields.multi_select_field.select_values')
        "
        :empty-label="
            t(
                'i18n.components.fields.multi_select_field.there_are_no_selection_values',
            )
        "
    />
</template>
