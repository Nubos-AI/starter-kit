<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useFormulaBackfill } from '@/composables/useFormulaBackfill';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = defineProps<{
    objectTypeSlug: string;
}>();

const emit = defineEmits<{
    completed: [];
}>();

const { run, isCancelling, cancel } = useFormulaBackfill(props.objectTypeSlug, {
    onCompleted: (): void => emit('completed'),
});

const dismissed = ref<boolean>(false);

const isRunning = computed<boolean>(() => {
    const current = run.value;

    return (
        current !== null &&
        (current.status === 'pending' || current.status === 'running')
    );
});

const isVisible = computed<boolean>(() => {
    const current = run.value;

    if (current === null || current.totalCount === 0 || dismissed.value) {
        return false;
    }

    if (current.status === 'completed') {
        return current.errorCount > 0;
    }

    return true;
});

const message = computed<string>(() => {
    const current = run.value;

    if (current === null) {
        return '';
    }

    if (isRunning.value) {
        return t(
            'i18n.components.engine.formula_backfill_banner.recalculating_formula_values_of_records_processed',
            { value1: current.processedCount, value2: current.totalCount },
        );
    }

    if (current.status === 'cancelled') {
        return t(
            'i18n.components.engine.formula_backfill_banner.formula_backfill_cancelled_stopped_after_of_records',
            { value1: current.processedCount, value2: current.totalCount },
        );
    }

    if (current.status === 'failed') {
        return t(
            'i18n.components.engine.formula_backfill_banner.formula_backfill_failed_of_records_reported_an_error',
            { value1: current.errorCount, value2: current.totalCount },
        );
    }

    return t(
        'i18n.components.engine.formula_backfill_banner.formula_backfill_finished_of_records_reported_an_error',
        { value1: current.errorCount, value2: current.totalCount },
    );
});
</script>

<template>
    <div
        v-if="isVisible"
        role="status"
        aria-live="polite"
        data-formula-backfill
        class="mx-4 mb-2 flex shrink-0 items-center justify-between gap-3 rounded-md border border-border bg-muted/50 px-3 py-2 text-sm"
    >
        <span>{{ message }}</span>

        <Button
            v-if="isRunning"
            variant="outline"
            size="sm"
            :disabled="isCancelling"
            @click="cancel"
        >
            {{
                t(
                    'i18n.components.engine.formula_backfill_banner.cancel_backfill',
                )
            }}
        </Button>

        <Button v-else variant="ghost" size="sm" @click="dismissed = true">
            {{ t('i18n.components.engine.formula_backfill_banner.dismiss') }}
        </Button>
    </div>
</template>
