<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { ObjectTypeSummary } from '@/domains/business-data/data';
import { useObjectTypeSection } from '@/domains/business-data/ui-object-types';
import type { FieldGroupRow } from '@/domains/business-data/ui-record-field';
import type { ObjectTypeFieldRow } from '@/domains/business-data/util-record-format';
import { useI18n } from '@/shared/composables/useI18n';
import type { FieldTypeOption } from '@/shared/types/fields';
import type { SelectOption } from '@/shared/types/ui';
import CreateButton from '@/shared/ui/CreateButton.vue';
import TitledCard from '@/shared/ui/TitledCard.vue';
import ViewStates from '@/shared/ui/ViewStates.vue';
import type { RollupTargetOption } from '../../../types/rollups';
import ObjectTypeFieldDrawer from '../../../ui/ObjectTypeFieldDrawer.vue';
import ObjectTypeFieldGrid from '../../../ui/ObjectTypeFieldGrid.vue';
import ObjectTypeFieldGroupsCard from '../../../ui/ObjectTypeFieldGroupsCard.vue';

const { t } = useI18n();

const props = defineProps<{
  objectType: ObjectTypeSummary;
  fields: ObjectTypeFieldRow[];
  fieldGroups: FieldGroupRow[];
  fieldTypes: FieldTypeOption[];
  objectTypeOptions: SelectOption[];
  rollupTargets: RollupTargetOption[];
}>();

const { isEditable } = useObjectTypeSection(props.objectType, 'fields');

const fieldDrawerOpen = ref<boolean>(false);
const editingField = ref<ObjectTypeFieldRow | null>(null);

function openFieldCreate(): void {
  editingField.value = null;
  fieldDrawerOpen.value = true;
}

function openFieldEdit(field: ObjectTypeFieldRow): void {
  editingField.value = field;
  fieldDrawerOpen.value = true;
}
</script>

<template>
  <Head
    :title="
      t('i18n.pages.object_types.edit.fields.fields_2', {
        value1: objectType.label,
      })
    "
  />

  <ObjectTypeFieldGroupsCard :object-type-slug="objectType.slug" :groups="fieldGroups" :editable="isEditable" />

  <TitledCard
    :title="t('i18n.pages.object_types.edit.fields.fields')"
    :description="t('i18n.pages.object_types.edit.fields.field_definitions_describe_this_object_type_s_records_edit')"
  >
    <template #actions>
      <CreateButton
        v-if="isEditable"
        size="sm"
        :label="t('i18n.pages.object_types.edit.fields.new_field')"
        @click="openFieldCreate"
      />
    </template>

    <ObjectTypeFieldGrid
      v-if="fields.length > 0"
      :object-type-slug="objectType.slug"
      :fields="fields"
      :field-types="fieldTypes"
      :groups="fieldGroups"
      :editable="isEditable"
      @edit-field="openFieldEdit"
    />

    <div v-else class="relative flex min-h-40 flex-1 flex-col" data-field-empty>
      <ViewStates
        state="empty"
        empty-kind="no-records"
        :empty-title="t('i18n.pages.object_types.edit.fields.no_fields_defined_yet')"
        :empty-description="t('i18n.pages.object_types.edit.fields.add_a_first_field_to_describe_the_records_of')"
        :create-label="t('i18n.pages.object_types.edit.fields.new_field')"
        :can-create="isEditable"
        @create="openFieldCreate"
      />
    </div>
  </TitledCard>

  <ObjectTypeFieldDrawer
    v-if="isEditable"
    v-model:open="fieldDrawerOpen"
    :object-type-slug="objectType.slug"
    :field-types="fieldTypes"
    :object-type-options="objectTypeOptions"
    :groups="fieldGroups"
    :mode="editingField === null ? 'create' : 'edit'"
    :field="editingField"
    :fields="fields"
    :rollup-targets="rollupTargets"
  />
</template>
