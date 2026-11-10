<script setup lang="ts">
import { computed } from 'vue';
import { NumberInput } from '@/components/ui/number-input';
import { numberInputFormat } from '@/lib/localizedNumber';
import type { FieldDefinition } from '@/types/fields';

const props = defineProps<{
    field: FieldDefinition;
    error?: string;
    describedBy?: string;
}>();

const model = defineModel<string | number | null>();

const format = computed<Intl.NumberFormatOptions>(() =>
    numberInputFormat(props.field.field_type),
);
</script>

<template>
    <NumberInput
        :id="field.key"
        :model-value="model ?? null"
        :format="format"
        :required="field.is_required"
        :placeholder="field.placeholder ?? undefined"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="describedBy"
        @update:model-value="model = $event"
    />
</template>
