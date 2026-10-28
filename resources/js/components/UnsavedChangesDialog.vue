<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();
</script>

<template>
    <ConfirmDialog
        :open="open"
        :title="t('i18n.components.unsaved_changes_dialog.discard_changes')"
        :description="
            t(
                'i18n.components.unsaved_changes_dialog.this_form_has_unsaved_changes_they_will_be_lost',
            )
        "
        :confirm-label="t('i18n.components.unsaved_changes_dialog.yes_leave')"
        :cancel-label="t('i18n.components.unsaved_changes_dialog.no_stay_here')"
        variant="destructive"
        @confirm="emit('confirm')"
        @cancel="emit('cancel')"
    />
</template>
