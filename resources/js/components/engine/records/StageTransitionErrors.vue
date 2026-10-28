<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import type { GateViolationLine } from '@/lib/gateViolations';
import { gateViolationLines } from '@/lib/gateViolations';

const { t } = useI18n();

const HEADLINE = t(
    'i18n.components.engine.records.stage_transition_errors.the_stage_change_is_blocked',
);

const props = withDefaults(
    defineProps<{
        errors: Record<string, string[]>;
        fieldLabels?: Record<string, string>;
    }>(),
    { fieldLabels: () => ({}) },
);

const lines = computed<GateViolationLine[]>(() =>
    gateViolationLines(props.errors, props.fieldLabels),
);
</script>

<template>
    <div
        v-if="lines.length > 0"
        role="alert"
        aria-live="polite"
        class="flex flex-col gap-1 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
        data-transition-violations
    >
        <p class="font-medium">{{ HEADLINE }}</p>
        <p v-for="line in lines" :key="line.key" data-transition-violation>
            {{ line.message }}
        </p>
    </div>
</template>
