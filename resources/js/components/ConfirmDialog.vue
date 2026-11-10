<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        variant?: 'default' | 'destructive';
        pending?: boolean;
        confirmDisabled?: boolean;
        confirmAttrs?: Record<string, string>;
        cancelAttrs?: Record<string, string>;
    }>(),
    {
        confirmLabel: undefined,
        cancelLabel: undefined,
        variant: 'default',
        pending: false,
        confirmDisabled: false,
        confirmAttrs: () => ({}),
        cancelAttrs: () => ({}),
    },
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
    'update:open': [value: boolean];
}>();

function onCancel(): void {
    emit('cancel');
    emit('update:open', false);
}
</script>

<template>
    <Dialog
        :open="props.open"
        @update:open="(value) => (value ? undefined : onCancel())"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ props.title }}</DialogTitle>
                <DialogDescription v-if="props.description">
                    {{ props.description }}
                </DialogDescription>
            </DialogHeader>

            <slot />

            <DialogFooter>
                <Button
                    v-bind="props.cancelAttrs"
                    variant="outline"
                    :disabled="props.pending"
                    @click="onCancel"
                >
                    {{
                        props.cancelLabel ??
                        t('i18n.components.confirm_dialog.cancel')
                    }}
                </Button>
                <Button
                    v-bind="props.confirmAttrs"
                    :variant="props.variant"
                    :disabled="props.pending || props.confirmDisabled"
                    data-confirm-dialog-confirm
                    @click="emit('confirm')"
                >
                    {{
                        props.confirmLabel ??
                        t('i18n.components.confirm_dialog.confirm')
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
