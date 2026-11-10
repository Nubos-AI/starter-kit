<script setup lang="ts">
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import {
    formatLocalizedNumber,
    parseLocalizedNumber,
} from '@/lib/localizedNumber';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        format?: Intl.NumberFormatOptions;
        name?: string;
    }>(),
    { modelValue: null, format: () => ({}), name: undefined },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const focused = ref<boolean>(false);
const text = ref<string>(formatLocalizedNumber(props.modelValue, props.format));

function editableText(): string {
    return formatLocalizedNumber(props.modelValue, {
        ...props.format,
        minimumFractionDigits: 0,
        useGrouping: false,
    });
}

watch(
    () => props.modelValue,
    () => {
        if (!focused.value) {
            text.value = formatLocalizedNumber(props.modelValue, props.format);
        }
    },
);

function onFocus(): void {
    focused.value = true;
    text.value = editableText();
}

function onBlur(): void {
    focused.value = false;
    text.value = formatLocalizedNumber(props.modelValue, props.format);
}

function onInput(value: string | number): void {
    text.value = String(value);
    emit('update:modelValue', parseLocalizedNumber(text.value));
}
</script>

<template>
    <div class="contents">
        <Input
            v-bind="$attrs"
            data-number-input
            type="text"
            inputmode="decimal"
            :model-value="text"
            @focus="onFocus"
            @blur="onBlur"
        @update:model-value="onInput"
        />
        <input
            v-if="name !== undefined"
            type="hidden"
            :name="name"
            :value="modelValue ?? ''"
        />
    </div>
</template>
