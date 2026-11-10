<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { FileCheck, Upload } from '@lucide/vue';
import { ref } from 'vue';
import { cn } from '@/lib/utils';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        inputId: string;
        accept: string;
        label?: string;
        hint?: string;
        fileName?: string | null;
        disabled?: boolean;
    }>(),
    {
        label: undefined,
        hint: undefined,
        fileName: null,
        disabled: false,
    },
);

const emit = defineEmits<{
    select: [file: File];
}>();

const isDragging = ref<boolean>(false);
const fileInput = ref<HTMLInputElement | null>(null);

function openPicker(): void {
    if (props.disabled) {
        return;
    }

    fileInput.value?.click();
}

function onDragOver(event: DragEvent): void {
    event.preventDefault();

    if (!props.disabled) {
        isDragging.value = true;
    }
}

function onDragLeave(): void {
    isDragging.value = false;
}

function onDrop(event: DragEvent): void {
    event.preventDefault();
    isDragging.value = false;

    if (props.disabled) {
        return;
    }

    const file = event.dataTransfer?.files?.[0];

    if (file !== undefined) {
        emit('select', file);
    }
}

function onChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file !== undefined) {
        emit('select', file);
    }

    input.value = '';
}
</script>

<template>
    <div
        data-slot="file-dropzone"
        :data-dragging="String(isDragging)"
        role="button"
        tabindex="0"
        :aria-disabled="props.disabled"
        :aria-label="(props.label ?? t('i18n.components.ui.file_dropzone.file_dropzone.drag_a_file_here_or_click_to_select'))"
        :class="
            cn(
                'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border border-dashed border-input px-4 py-6 text-center transition-colors outline-none hover:bg-muted/50 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring',
                isDragging && 'border-primary bg-primary/5',
                props.disabled && 'pointer-events-none opacity-60',
            )
        "
        @click="openPicker"
        @keydown.enter.prevent="openPicker"
        @keydown.space.prevent="openPicker"
        @dragover="onDragOver"
        @dragenter="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
    >
        <component
            :is="props.fileName ? FileCheck : Upload"
            class="size-5 text-muted-foreground"
        />

        <p v-if="props.fileName" class="text-sm font-medium">
            {{ props.fileName }}
        </p>
        <p v-else class="text-sm text-muted-foreground">{{ (props.label ?? t('i18n.components.ui.file_dropzone.file_dropzone.drag_a_file_here_or_click_to_select')) }}</p>

        <p v-if="props.hint" class="text-xs text-muted-foreground">
            {{ props.hint }}
        </p>

        <input
            :id="props.inputId"
            ref="fileInput"
            type="file"
            class="hidden"
            :accept="props.accept"
            :disabled="props.disabled"
            @change="onChange"
        />
    </div>
</template>
