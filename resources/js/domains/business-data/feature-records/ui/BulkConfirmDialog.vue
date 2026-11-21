<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import type { BulkAction } from '@/shared/records/composables/useRecordSelection';
import type { FieldDefinition } from '@/shared/types/fields';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Input } from '@/shared/ui/primitives/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import { Textarea } from '@/shared/ui/primitives/textarea';
import { isComplexField } from './cellEditors';

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
  'soft-delete': t('i18n.components.engine.bulk_confirm_dialog.delete_records'),
  'export-csv': t('i18n.components.engine.bulk_confirm_dialog.export_csv'),
};

const ACTION_EFFECTS: Record<BulkAction, string> = {
  'set-field': t('i18n.components.engine.bulk_confirm_dialog.the_selected_field_value_will_be_applied_to_the'),
  'soft-delete': t('i18n.components.engine.bulk_confirm_dialog.the_selected_records_will_be_moved_to_the_trash'),
  'export-csv': t('i18n.components.engine.bulk_confirm_dialog.a_csv_file_will_be_generated_for_the_selected'),
};

const selectedField = ref<string>('');
const fieldValue = ref<string>('');
const deletionReason = ref<string>('');

const writableFields = computed<FieldDefinition[]>(() =>
  (props.fieldDefinitions ?? []).filter((field) => !isComplexField(field.field_type)),
);

const title = computed<string>(() => (props.action !== null ? ACTION_TITLES[props.action] : ''));

const effect = computed<string>(() => (props.action !== null ? ACTION_EFFECTS[props.action] : ''));

const AFFECTED_LABEL = t('i18n.components.engine.bulk_confirm_dialog.affected');

const description = computed<string>(() =>
  props.action === null ? '' : `${effect.value} ${AFFECTED_LABEL} ${props.countLabel}.`,
);

const isDestructive = computed<boolean>(() => props.action === 'soft-delete');

const needsReason = computed<boolean>(() => isDestructive.value && props.requiresDeletionReason);

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
  <ConfirmDialog
    :open="open"
    :title="title"
    :description="description"
    :confirm-label="t('i18n.components.engine.bulk_confirm_dialog.confirm')"
    :cancel-label="t('i18n.components.engine.bulk_confirm_dialog.cancel')"
    :variant="isDestructive ? 'destructive' : 'default'"
    :confirm-disabled="!canConfirm"
    @update:open="(value) => emit('update:open', value)"
    @confirm="onConfirm"
  >
    <div v-if="action === 'set-field'" class="grid gap-4">
      <FormField for="bulk_set_field" :label="t('i18n.components.engine.bulk_confirm_dialog.field')">
        <Select v-model="selectedField">
          <SelectTrigger id="bulk_set_field" class="w-full">
            <SelectValue :placeholder="t('i18n.components.engine.bulk_confirm_dialog.select_field')" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="field in writableFields" :key="field.key" :value="field.key">
              {{ field.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>
      <FormField for="bulk_set_value" :label="t('i18n.components.engine.bulk_confirm_dialog.new_value')">
        <Input
          id="bulk_set_value"
          v-model="fieldValue"
          :placeholder="t('i18n.components.engine.bulk_confirm_dialog.new_value')"
        />
      </FormField>
    </div>

    <FormField
      v-if="needsReason"
      for="bulk_deletion_reason"
      :label="t('i18n.components.engine.bulk_confirm_dialog.reason_for_deletion')"
    >
      <Textarea
        id="bulk_deletion_reason"
        v-model="deletionReason"
        rows="3"
        data-testid="bulk-deletion-reason"
        :placeholder="t('i18n.components.engine.bulk_confirm_dialog.why_are_these_records_being_deleted')"
      />
    </FormField>

    <InputError
      v-if="isDestructive"
      :message="t('i18n.components.engine.bulk_confirm_dialog.warning_this_action_moves_the_records_to_the_trash')"
    />
  </ConfirmDialog>
</template>
