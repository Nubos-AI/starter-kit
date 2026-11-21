<script setup lang="ts">
import { computed, ref } from 'vue';
import type { SegmentSummary } from '@/shared/business-data/data/useSegments';
import { useSegments } from '@/shared/business-data/data/useSegments';
import type { RecordObjectType } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import FormDialog from '@/shared/ui/FormDialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Input } from '@/shared/ui/primitives/input';
import FilterBuilder from '../../filters/ui/FilterBuilder.vue';

const { t } = useI18n();

const props = defineProps<{
  open: boolean;
  objectType: RecordObjectType;
  fields: FieldDefinition[];
}>();

const emit = defineEmits<{
  'update:open': [value: boolean];
  saved: [segment: SegmentSummary];
}>();

const { save, error } = useSegments(props.objectType);

const filterableFields = computed<FieldDefinition[]>(() =>
  props.fields.filter((field) => field.is_filterable ?? false),
);

const name = ref<string>('');
const pendingTree = ref<FilterGroupNode | null>(null);
const saving = ref<boolean>(false);

const hasFilterConditions = computed<boolean>(() => (pendingTree.value?.conditions.length ?? 0) > 0);

const isComplete = computed<boolean>(() => name.value.trim() !== '' && hasFilterConditions.value);

const saveDisabled = computed<boolean>(() => saving.value || !isComplete.value);

function onApply(tree: FilterGroupNode): void {
  pendingTree.value = tree;
}

function onReset(): void {
  pendingTree.value = null;
}

function resetForm(): void {
  name.value = '';
  pendingTree.value = null;
}

function onOpenChange(value: boolean): void {
  if (!value) {
    resetForm();
    emit('update:open', false);
  }
}

async function onSave(): Promise<void> {
  if (saveDisabled.value) {
    return;
  }

  saving.value = true;

  try {
    const saved = await save({
      name: name.value.trim(),
      object_type_id: props.objectType.id,
      filter_definition: pendingTree.value,
    });

    if (saved === null) {
      return;
    }

    emit('saved', saved);
    resetForm();
    emit('update:open', false);
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <FormDialog
    :open="open"
    :title="t('i18n.components.engine.segment.segment_save_dialog.create_segment')"
    :dirty="isComplete"
    :processing="saving"
    @update:open="onOpenChange"
    @save="onSave"
  >
    <template #description>
      {{ t('i18n.components.engine.segment.segment_save_dialog.create_a_segment_for') }}
      {{ objectType.label }}
      {{ t('i18n.components.engine.segment.segment_save_dialog.example') }}
    </template>

    <FormField for="segment-name" :label="t('i18n.components.engine.segment.segment_save_dialog.name')">
      <Input
        id="segment-name"
        v-model="name"
        data-segment-name
        :placeholder="t('i18n.components.engine.segment.segment_save_dialog.segment_name')"
      />
    </FormField>

    <FilterBuilder
      v-if="filterableFields.length > 0"
      :fields="filterableFields"
      :show-actions="false"
      @update:model-value="onApply"
      @reset="onReset"
    />

    <p v-else class="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">
      {{
        t('i18n.components.engine.segment.segment_save_dialog.no_filterable_fields_are_configured_for_this_object_type')
      }}
    </p>

    <InputError v-if="error !== null" role="alert" :message="error" />
  </FormDialog>
</template>
