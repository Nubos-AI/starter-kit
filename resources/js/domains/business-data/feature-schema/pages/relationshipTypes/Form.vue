<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RelationshipTypesController from '@/actions/App/Http/Controllers/Engine/RelationshipTypesController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { SelectOption } from '@/shared/types/ui';
import FormActions from '@/shared/ui/FormActions.vue';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Input } from '@/shared/ui/primitives/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

const { t } = useI18n();

interface RelationshipTypePayload {
  id: string;
  key: string;
  name: string;
  inverse_name: string;
  from_object_type_id: string;
  to_object_type_id: string;
  cardinality: string;
  cascade_behavior: string;
  is_required: boolean;
}

const props = defineProps<{
  mode: 'create' | 'edit';
  relationshipType: RelationshipTypePayload | null;
  objectTypeOptions: SelectOption[];
  cardinalities: SelectOption[];
  cascadeBehaviors: SelectOption[];
}>();

const heading = computed<string>(() =>
  props.mode === 'create'
    ? t('i18n.pages.relationship_types.form.new_relationship_type')
    : (props.relationshipType?.name ?? t('i18n.pages.relationship_types.form.relationship_type')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const defaults = computed(() => ({
  from_object_type_id: props.relationshipType?.from_object_type_id ?? props.objectTypeOptions[0]?.value ?? '',
  to_object_type_id: props.relationshipType?.to_object_type_id ?? props.objectTypeOptions[0]?.value ?? '',
  name: props.relationshipType?.name ?? '',
  inverse_name: props.relationshipType?.inverse_name ?? '',
  cardinality: props.relationshipType?.cardinality ?? props.cardinalities[0]?.value ?? '',
  cascade_behavior: props.relationshipType?.cascade_behavior ?? props.cascadeBehaviors[0]?.value ?? '',
  is_required: props.relationshipType?.is_required ?? false,
}));

const name = ref<string>(defaults.value.name);
const inverseName = ref<string>(defaults.value.inverse_name);
const fromObjectTypeId = ref<string | null>(defaults.value.from_object_type_id);
const toObjectTypeId = ref<string | null>(defaults.value.to_object_type_id);
const cardinality = ref<string>(defaults.value.cardinality);
const cascadeBehavior = ref<string>(defaults.value.cascade_behavior);
const isRequired = ref<boolean>(defaults.value.is_required);

const formAction = computed(() =>
  props.mode === 'create'
    ? RelationshipTypesController.store.form()
    : RelationshipTypesController.update.form({
        relationshipType: props.relationshipType!.id,
      }),
);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    name: name.value,
    inverseName: inverseName.value,
    fromObjectTypeId: fromObjectTypeId.value,
    toObjectTypeId: toObjectTypeId.value,
    cardinality: cardinality.value,
    cascadeBehavior: cascadeBehavior.value,
    isRequired: isRequired.value,
  }),
  backHref: RelationshipTypesController.index.url(),
});
</script>

<template>
  <PageShell
    :title="heading"
    :description="
      mode === 'create'
        ? t('i18n.pages.relationship_types.form.define_how_records_of_one_object_type_relate_to')
        : t('i18n.pages.relationship_types.form.edit_this_relationship_type')
    "
    :head-title="
      mode === 'create'
        ? t('i18n.pages.relationship_types.form.new_relationship_type')
        : t('i18n.pages.relationship_types.form.edit', {
            value1: relationshipType?.name,
          })
    "
  >
    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.relationship_types.form.details') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.relationship_types.form.records_are_linked_to_each_other_through_this_type') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form v-bind="formAction" :on-success="markSaved" class="flex flex-col gap-4" v-slot="{ errors, processing }">
          <div class="grid gap-4 sm:grid-cols-2">
            <FormField
              for="from-type"
              :label="t('i18n.pages.relationship_types.form.source_object_type')"
              :error="errors.from_object_type_id"
            >
              <input type="hidden" name="from_object_type_id" :value="fromObjectTypeId ?? ''" />
              <Combobox
                id="from-type"
                v-model="fromObjectTypeId"
                :options="props.objectTypeOptions"
                :searchable="props.objectTypeOptions.length > 8"
                :placeholder="t('i18n.pages.relationship_types.form.select_source_object_type')"
              />
            </FormField>

            <FormField
              for="to-type"
              :label="t('i18n.pages.relationship_types.form.target_object_type')"
              :error="errors.to_object_type_id"
            >
              <input type="hidden" name="to_object_type_id" :value="toObjectTypeId ?? ''" />
              <Combobox
                id="to-type"
                v-model="toObjectTypeId"
                :options="props.objectTypeOptions"
                :searchable="props.objectTypeOptions.length > 8"
                :placeholder="t('i18n.pages.relationship_types.form.select_target_object_type')"
              />
            </FormField>

            <FormField
              for="rel-name"
              :label="t('i18n.pages.relationship_types.form.label_source_target')"
              :error="errors.name"
            >
              <Input
                id="rel-name"
                v-model="name"
                name="name"
                :placeholder="t('i18n.pages.relationship_types.form.e_g_orders')"
                required
              />
            </FormField>

            <FormField
              for="rel-inverse"
              :label="t('i18n.pages.relationship_types.form.reverse_label_target_source')"
              :error="errors.inverse_name"
            >
              <Input
                id="rel-inverse"
                v-model="inverseName"
                name="inverse_name"
                :placeholder="t('i18n.pages.relationship_types.form.e_g_company')"
                required
              />
            </FormField>

            <FormField
              for="rel-cardinality"
              :label="t('i18n.pages.relationship_types.form.cardinality')"
              :error="errors.cardinality"
            >
              <Select v-model="cardinality" name="cardinality">
                <SelectTrigger id="rel-cardinality" class="w-full">
                  <SelectValue :placeholder="t('i18n.pages.relationship_types.form.select_cardinality')" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="option in props.cardinalities" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </FormField>

            <FormField
              for="rel-cascade"
              :label="t('i18n.pages.relationship_types.form.deletion_behaviour')"
              :error="errors.cascade_behavior"
            >
              <Select v-model="cascadeBehavior" name="cascade_behavior">
                <SelectTrigger id="rel-cascade" class="w-full">
                  <SelectValue :placeholder="t('i18n.pages.relationship_types.form.select_deletion_behaviour')" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="option in props.cascadeBehaviors" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </FormField>
          </div>

          <FormCheckbox
            v-model="isRequired"
            name="is_required"
            :label="t('i18n.pages.relationship_types.form.required_relationship')"
          />

          <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </Form>
      </CardContent>
    </Card>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
