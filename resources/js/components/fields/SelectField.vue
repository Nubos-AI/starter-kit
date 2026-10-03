<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { normalizeFieldOptions } from '@/composables/useFieldTypeRegistry';
import { useI18n } from '@/composables/useI18n';
import type { FieldDefinition } from '@/types/fields';

const { t } = useI18n();

const props = defineProps<{
    field: FieldDefinition;
    error?: string;
    describedBy?: string;
}>();

const model = defineModel<string | null>();

const options = computed(() =>
    normalizeFieldOptions(props.field.config?.options),
);
</script>

<template>
    <Select
        :name="field.key"
        :model-value="model ?? undefined"
        @update:model-value="model = ($event as string | undefined) ?? null"
    >
        <SelectTrigger
            :id="field.key"
            class="w-full"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="describedBy"
        >
            <SelectValue
                :placeholder="
                    field.placeholder ??
                    t('i18n.components.fields.select_field.please_select')
                "
            />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
