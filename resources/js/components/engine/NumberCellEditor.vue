<script setup lang="ts">
import { onMounted, ref, useTemplateRef } from 'vue';
import { NumberInput } from '@/components/ui/number-input';

const props = defineProps<{
    params: { value: unknown; format: Intl.NumberFormatOptions };
}>();

const value = ref<string | number | null>(
    typeof props.params.value === 'string' ||
        typeof props.params.value === 'number'
        ? props.params.value
        : null,
);

const editor = useTemplateRef<{ $el: HTMLElement }>('editor');

onMounted(() => {
    editor.value?.$el.querySelector('input')?.focus();
});

function getValue(): string | number | null {
    return value.value === '' ? null : value.value;
}

defineExpose({ getValue });
</script>

<template>
    <NumberInput
        ref="editor"
        v-model="value"
        :format="params.format"
        class="h-full rounded-none border-0"
    />
</template>
