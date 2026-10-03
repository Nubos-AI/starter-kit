<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import type { FormulaErrorValue } from '@/types/formulas';
import { formulaErrorLabels, isFormulaErrorValue } from '@/types/formulas';

const { t } = useI18n();

const props = defineProps<{
    value: unknown;
}>();

const errorValue = computed<FormulaErrorValue | null>(() => {
    const value = props.value;

    return isFormulaErrorValue(value) ? value : null;
});

const errorLabel = computed<string>(() => {
    const error = errorValue.value;

    return error === null ? '' : formulaErrorLabels[error.code];
});

const errorDescription = computed<string>(() => {
    const error = errorValue.value;

    if (error === null) {
        return '';
    }

    return error.field_key === null
        ? t('i18n.components.engine.formula_error_cell.formula_error', {
              value1: errorLabel.value,
          })
        : t(
              'i18n.components.engine.formula_error_cell.formula_error_in_field',
              { value1: error.field_key, value2: errorLabel.value },
          );
});

const readableValue = computed<string>(() => {
    const value = props.value;

    if (value === null || value === undefined || value === '') {
        return '';
    }

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
});
</script>

<template>
    <span>
        <span
            v-if="errorValue !== null"
            :data-formula-error="errorValue.code"
            :title="errorDescription"
            :aria-label="errorDescription"
            class="inline-flex max-w-full items-center gap-1 truncate rounded-sm bg-destructive/10 px-1.5 py-0.5 text-destructive"
        >
            {{ errorLabel }}
        </span>
        <template v-else>{{ readableValue }}</template>
    </span>
</template>
