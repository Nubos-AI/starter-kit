<script setup lang="ts">
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        dirty: boolean;
        processing?: boolean;
        type?: 'submit' | 'button';
        cancelLabel?: string;
        cancellable?: boolean;
    }>(),
    {
        processing: false,
        type: 'submit',
        cancelLabel: undefined,
        cancellable: true,
    },
);

const emit = defineEmits<{
    save: [];
    cancel: [];
}>();
</script>

<template>
    <div class="flex items-center justify-end gap-2" data-form-actions>
        <UiExtensionPoint name="forms.actions" :context="$props" />
        <Button
            v-if="props.cancellable"
            type="button"
            variant="outline"
            data-form-cancel
            @click="emit('cancel')"
        >
            {{ props.cancelLabel ?? t('i18n.components.form_actions.cancel') }}
        </Button>

        <Button
            :type="props.type"
            :disabled="props.processing || !props.dirty"
            data-form-save
            @click="emit('save')"
        >
            {{ t('i18n.components.form_actions.save') }}
        </Button>
    </div>
</template>
