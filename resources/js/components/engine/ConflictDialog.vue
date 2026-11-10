<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    reload: [];
    close: [];
}>();
</script>

<template>
    <ConfirmDialog
        :open="open"
        :title="
            t(
                'i18n.components.engine.conflict_dialog.this_record_has_been_changed',
            )
        "
        :description="
            t(
                'i18n.components.engine.conflict_dialog.another_user_has_changed_this_record_the_current_values',
            )
        "
        :confirm-label="
            t('i18n.components.engine.conflict_dialog.load_current_values')
        "
        :cancel-label="t('i18n.components.engine.conflict_dialog.close')"
        @confirm="emit('reload')"
        @cancel="emit('close')"
    />
</template>
