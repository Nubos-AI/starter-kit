<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import type { FieldDefinition } from '@/types/fields';

defineProps<{
    field: FieldDefinition;
    error?: string;
    describedBy?: string;
}>();

const model = defineModel<boolean | null>();
</script>

<template>
    <Checkbox
        :id="field.key"
        :name="field.key"
        :model-value="model ?? false"
        :required="field.is_required"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="describedBy"
        @update:model-value="model = $event === true"
    />
</template>
