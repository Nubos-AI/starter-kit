<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        open: boolean;
        description: string;
        pending?: boolean;
        requiresReason?: boolean;
        error?: string | null;
    }>(),
    { pending: false, requiresReason: false, error: null },
);

const reason = defineModel<string>('reason', { required: true });

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();
</script>

<template>
    <ConfirmDialog
        :open="props.open"
        :title="
            t(
                'i18n.components.engine.records.record_delete_dialog.delete_record',
            )
        "
        :description="props.description"
        :confirm-label="
            t('i18n.components.engine.records.record_delete_dialog.delete')
        "
        variant="destructive"
        :pending="props.pending"
        @confirm="emit('confirm')"
        @cancel="emit('cancel')"
    >
        <div v-if="props.requiresReason" class="grid gap-2">
            <Label for="deletion_reason">{{
                t(
                    'i18n.components.engine.records.record_delete_dialog.reason_for_deletion',
                )
            }}</Label>
            <Textarea
                id="deletion_reason"
                v-model="reason"
                name="deletion_reason"
                rows="3"
                data-testid="deletion-reason"
                :placeholder="
                    t(
                        'i18n.components.engine.records.record_delete_dialog.why_is_this_record_being_deleted',
                    )
                "
            />
            <InputError :message="props.error ?? undefined" />
        </div>
    </ConfirmDialog>
</template>
