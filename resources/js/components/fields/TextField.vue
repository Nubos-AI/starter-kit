<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { deriveValidationHints } from '@/composables/useFieldTypeRegistry';
import type { FieldDefinition } from '@/types/fields';

const props = defineProps<{
    field: FieldDefinition;
    inputType?: string;
    error?: string;
    describedBy?: string;
}>();

const model = defineModel<string | number | null>();

const hints = computed(() => deriveValidationHints(props.field));
</script>

<template>
    <Input
        :id="field.key"
        :name="field.key"
        :type="inputType ?? 'text'"
        :model-value="model ?? ''"
        :required="field.is_required"
        :placeholder="field.placeholder ?? undefined"
        :maxlength="hints.maxLength"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="describedBy"
        @update:model-value="model = $event"
    />
</template>
