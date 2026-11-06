<script setup lang="ts">
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        id: string;
        options: SelectOption[];
        name?: string;
        invalid?: boolean;
        tabindex?: number;
    }>(),
    { name: 'salutation', invalid: false },
);

const model = defineModel<string>({ required: true });
</script>

<template>
    <div>
        <Select v-model="model" :name="props.name">
            <SelectTrigger
                :id="props.id"
                :tabindex="props.tabindex"
                class="w-full"
                :aria-invalid="props.invalid || undefined"
                data-salutation-select
            >
                <SelectValue
                    :placeholder="
                        t('i18n.components.salutation_select.select_salutation')
                    "
                />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="option in props.options"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
