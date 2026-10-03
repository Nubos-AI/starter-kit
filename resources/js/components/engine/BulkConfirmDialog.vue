<script setup lang="ts">
import { computed, watch } from 'vue';
import { ref } from 'vue';
import { isComplexField } from '@/components/engine/cellEditors';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type { BulkAction } from '@/composables/useRecordSelection';
import type { FieldDefinition } from '@/types/fields';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        open: boolean;
        action: BulkAction | null;
        countLabel: string;
        fieldDefinitions?: FieldDefinition[];
        requiresDeletionReason?: boolean;
    }>(),
    { requiresDeletionReason: false },
);

const emit = defineEmits<{
    confirm: [payload?: Record<string, unknown>];
    'update:open': [value: boolean];
}>();

const ACTION_TITLES: Record<BulkAction, string> = {
    'set-field': t('i18n.components.engine.bulk_confirm_dialog.set_field'),
    'soft-delete': t(
        'i18n.components.engine.bulk_confirm_dialog.delete_records',
    ),
    restore: t('i18n.components.engine.bulk_confirm_dialog.restore_records'),
    'export-csv': t('i18n.components.engine.bulk_confirm_dialog.export_csv'),
};

const ACTION_EFFECTS: Record<BulkAction, string> = {
    'set-field': t(
        'i18n.components.engine.bulk_confirm_dialog.the_selected_field_value_will_be_applied_to_the',
    ),
    'soft-delete': t(
        'i18n.components.engine.bulk_confirm_dialog.the_selected_records_will_be_moved_to_the_trash',
    ),
    restore: t(
        'i18n.components.engine.bulk_confirm_dialog.the_selected_records_will_be_restored',
    ),
    'export-csv': t(
        'i18n.components.engine.bulk_confirm_dialog.a_csv_file_will_be_generated_for_the_selected',
    ),
};

const selectedField = ref<string>('');
const fieldValue = ref<string>('');
const deletionReason = ref<string>('');

const writableFields = computed<FieldDefinition[]>(() =>
    (props.fieldDefinitions ?? []).filter(
        (field) => !isComplexField(field.field_type),
    ),
);

const title = computed<string>(() =>
    props.action !== null ? ACTION_TITLES[props.action] : '',
);

const effect = computed<string>(() =>
    props.action !== null ? ACTION_EFFECTS[props.action] : '',
);

const isDestructive = computed<boolean>(() => props.action === 'soft-delete');

const needsReason = computed<boolean>(
    () => isDestructive.value && props.requiresDeletionReason,
);

const canConfirm = computed<boolean>(() => {
    if (props.action === 'set-field') {
        return selectedField.value !== '';
    }

    return !needsReason.value || deletionReason.value.trim() !== '';
});

watch(
    () => props.open,
    (value) => {
        if (!value) {
            selectedField.value = '';
            fieldValue.value = '';
            deletionReason.value = '';
        }
    },
);

const onConfirm = (): void => {
    if (!canConfirm.value) {
        return;
    }

    if (props.action === 'set-field') {
        emit('confirm', { [selectedField.value]: fieldValue.value });
    } else if (needsReason.value) {
        emit('confirm', { deletion_reason: deletionReason.value.trim() });
    } else {
        emit('confirm');
    }

    emit('update:open', false);
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    {{ effect }}
                    {{
                        t('i18n.components.engine.bulk_confirm_dialog.affected')
                    }}
                    {{ countLabel }}.
                </DialogDescription>
            </DialogHeader>

            <div v-if="action === 'set-field'" class="flex flex-col gap-3 py-2">
                <Select
                    :model-value="selectedField"
                    @update:model-value="
                        selectedField = ($event as string) ?? ''
                    "
                >
                    <SelectTrigger
                        class="w-full"
                        :aria-label="
                            t(
                                'i18n.components.engine.bulk_confirm_dialog.field',
                            )
                        "
                    >
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.components.engine.bulk_confirm_dialog.select_field',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="field in writableFields"
                            :key="field.key"
                            :value="field.key"
                        >
                            {{ field.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Input
                    v-model="fieldValue"
                    :aria-label="
                        t(
                            'i18n.components.engine.bulk_confirm_dialog.new_value',
                        )
                    "
                    :placeholder="
                        t(
                            'i18n.components.engine.bulk_confirm_dialog.new_value',
                        )
                    "
                />
            </div>

            <div v-if="needsReason" class="grid gap-2 py-2">
                <Label for="bulk_deletion_reason">{{
                    t(
                        'i18n.components.engine.bulk_confirm_dialog.reason_for_deletion',
                    )
                }}</Label>
                <Textarea
                    id="bulk_deletion_reason"
                    v-model="deletionReason"
                    rows="3"
                    data-testid="bulk-deletion-reason"
                    :placeholder="
                        t(
                            'i18n.components.engine.bulk_confirm_dialog.why_are_these_records_being_deleted',
                        )
                    "
                />
            </div>

            <p v-if="isDestructive" class="text-sm text-destructive">
                {{
                    t(
                        'i18n.components.engine.bulk_confirm_dialog.warning_this_action_moves_the_records_to_the_trash',
                    )
                }}
            </p>

            <DialogFooter>
                <Button variant="outline" @click="emit('update:open', false)">
                    {{ t('i18n.components.engine.bulk_confirm_dialog.cancel') }}
                </Button>
                <Button
                    :variant="isDestructive ? 'destructive' : 'default'"
                    :disabled="!canConfirm"
                    @click="onConfirm"
                >
                    {{
                        t('i18n.components.engine.bulk_confirm_dialog.confirm')
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
