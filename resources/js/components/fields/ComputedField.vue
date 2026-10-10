<script setup lang="ts">
import { computed } from 'vue';
import FormulaErrorCell from '@/components/engine/FormulaErrorCell.vue';
import type { FieldDefinition } from '@/types/fields';

const props = defineProps<{
    field: FieldDefinition;
    describedBy?: string;
}>();

const model = defineModel<unknown>();

const isEmpty = computed<boolean>(
    () =>
        model.value === null || model.value === undefined || model.value === '',
);
</script>

<template>
    <div
        :id="props.field.key"
        :data-computed-field="props.field.key"
        :aria-describedby="props.describedBy"
        aria-readonly="true"
        class="flex min-h-8 items-center rounded-md border border-input bg-muted px-3 py-1 text-sm"
    >
        <span
            v-if="isEmpty"
            data-computed-field-empty
            class="text-muted-foreground"
            >—</span
        >
        <FormulaErrorCell v-else class="truncate" :value="model" />
    </div>
</template>
