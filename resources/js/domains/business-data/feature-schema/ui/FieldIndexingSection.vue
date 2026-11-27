<script setup lang="ts">
import { ref, useId, watch } from 'vue';
import type { ObjectTypeFieldRow } from '@/domains/business-data/util-record-format';
import { useI18n } from '@/shared/composables/useI18n';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Input } from '@/shared/ui/primitives/input';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';

const { t } = useI18n();

const props = defineProps<{
  field: ObjectTypeFieldRow | null;
  errorMessage?: string;
}>();

const controlId = useId();

const encryptionHint = t(
  'i18n.components.engine.object_type.field_indexing_section.encrypted_fields_cannot_be_sorted_filtered_or_checked_for',
);

const isRequired = ref<boolean>(props.field?.is_required ?? false);
const isUnique = ref<boolean>(props.field?.is_unique ?? false);
const isSearchable = ref<boolean>(props.field?.is_searchable ?? false);
const isEncrypted = ref<boolean>(props.field?.is_encrypted ?? false);
const isSortable = ref<boolean>(props.field?.is_sortable ?? true);
const isFilterable = ref<boolean>(props.field?.is_filterable ?? true);
const isDefaultColumn = ref<boolean>(props.field?.is_default_column ?? true);
const listPosition = ref<string>(
  props.field?.list_position === null || props.field?.list_position === undefined
    ? ''
    : String(props.field.list_position),
);

watch(isEncrypted, (encrypted) => {
  if (!encrypted) {
    return;
  }

  isSortable.value = false;
  isFilterable.value = false;
  isUnique.value = false;
});
</script>

<template>
  <div data-testid="field-indexing-section" class="grid gap-3">
    <span class="text-sm leading-none font-medium">{{
      t('i18n.components.engine.object_type.field_indexing_section.behaviour')
    }}</span>

    <FormCheckbox
      v-model="isRequired"
      name="is_required"
      :label="t('i18n.components.engine.object_type.field_indexing_section.required_field')"
    />
    <FormCheckbox
      v-model="isDefaultColumn"
      name="is_default_column"
      :label="t('i18n.components.engine.object_type.field_indexing_section.default_column_in_lists')"
    />
    <UiExtensionPoint name="object-types.field-options" :context="{ field: props.field }" />
    <FormCheckbox
      v-model="isSearchable"
      name="is_searchable"
      :label="t('i18n.components.engine.object_type.field_indexing_section.searchable')"
    />
    <FormCheckbox
      v-model="isEncrypted"
      name="is_encrypted"
      :label="t('i18n.components.engine.object_type.field_indexing_section.store_encrypted')"
      :description="encryptionHint"
    />
    <FormCheckbox
      v-model="isSortable"
      name="is_sortable"
      :label="t('i18n.components.engine.object_type.field_indexing_section.sortable')"
      :disabled="isEncrypted"
    />
    <FormCheckbox
      v-model="isFilterable"
      name="is_filterable"
      :label="t('i18n.components.engine.object_type.field_indexing_section.filterable')"
      :disabled="isEncrypted"
    />
    <FormCheckbox
      v-model="isUnique"
      name="is_unique"
      :label="t('i18n.components.engine.object_type.field_indexing_section.unique')"
      :disabled="isEncrypted"
    />

    <FormField
      :for="`${controlId}_position`"
      :label="t('i18n.components.engine.object_type.field_indexing_section.position_in_the_list')"
    >
      <Input
        :id="`${controlId}_position`"
        v-model="listPosition"
        name="list_position"
        type="number"
        min="0"
        data-testid="field-list-position"
      />
    </FormField>

    <InputError :message="props.errorMessage" />
  </div>
</template>
