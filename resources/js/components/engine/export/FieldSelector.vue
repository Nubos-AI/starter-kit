<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { ExportFieldOption } from '@/composables/useExport';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = defineProps<{
    fields: ExportFieldOption[];
    modelValue: string[];
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
}>();

const selected = computed<Set<string>>(() => new Set(props.modelValue));

function toggle(key: string): void {
    const next = new Set(props.modelValue);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    emit('update:modelValue', [...next]);
}
</script>

<template>
    <fieldset class="flex flex-col gap-2">
        <legend class="mb-1 text-sm font-medium">
            {{
                t(
                    'i18n.components.engine.export.field_selector.fields_to_export',
                )
            }}
        </legend>

        <p v-if="fields.length === 0" class="text-sm text-muted-foreground">
            {{
                t(
                    'i18n.components.engine.export.field_selector.there_are_no_exportable_fields_for_this_object_type',
                )
            }}
        </p>

        <div
            v-for="field in fields"
            :key="field.key"
            class="flex items-center gap-2"
        >
            <Checkbox
                :id="`export-field-${field.key}`"
                :model-value="selected.has(field.key)"
                @update:model-value="toggle(field.key)"
            />
            <Label
                :for="`export-field-${field.key}`"
                class="text-sm font-normal"
            >
                {{ field.label }}
            </Label>
        </div>
    </fieldset>
</template>
