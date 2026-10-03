<script setup lang="ts">
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

defineProps<{
    sheets: string[];
    modelValue: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <div v-if="sheets.length > 1" class="flex flex-col gap-2">
        <Label for="import-sheet">{{
            t('i18n.components.engine.import.sheet_picker.select_worksheet')
        }}</Label>
        <Select
            :model-value="modelValue ?? undefined"
            @update:model-value="emit('update:modelValue', String($event))"
        >
            <SelectTrigger id="import-sheet" class="w-64">
                <SelectValue
                    :placeholder="
                        t(
                            'i18n.components.engine.import.sheet_picker.select_worksheet_2',
                        )
                    "
                />
            </SelectTrigger>
            <SelectContent>
                <SelectItem v-for="name in sheets" :key="name" :value="name">
                    {{ name }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
