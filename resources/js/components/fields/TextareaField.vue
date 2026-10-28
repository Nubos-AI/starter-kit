<script setup lang="ts">
import { computed } from 'vue';
import { Textarea } from '@/components/ui/textarea';
import { deriveValidationHints } from '@/composables/useFieldTypeRegistry';
import type { FieldDefinition } from '@/types/fields';

const props = defineProps<{
    field: FieldDefinition;
    error?: string;
    describedBy?: string;
}>();

const model = defineModel<string | null>();

const hints = computed(() => deriveValidationHints(props.field));
</script>

<template>
    <Textarea
        :id="field.key"
        :name="field.key"
        :model-value="model ?? ''"
        :required="field.is_required"
        :maxlength="hints.maxLength"
        :placeholder="field.placeholder ?? undefined"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="describedBy"
        class="min-h-20"
        @update:model-value="model = String($event)"
    />
</template>
