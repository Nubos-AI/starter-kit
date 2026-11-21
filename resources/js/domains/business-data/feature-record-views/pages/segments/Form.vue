<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import SegmentManagementController from '@/actions/App/Http/Controllers/Engine/SegmentManagementController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import type { SelectOption } from '@/shared/types/ui';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Input } from '@/shared/ui/primitives/input';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import FilterBuilder from '../../../../../shared/filters/ui/FilterBuilder.vue';

const { t } = useI18n();

interface SegmentPayload {
  id: string;
  name: string;
  object_type_id: string | null;
  filter_definition: FilterGroupNode | null;
}

const props = defineProps<{
  mode: 'create' | 'edit';
  segment: SegmentPayload | null;
  objectTypeOptions: SelectOption[];
  fieldsByType: Record<string, FieldDefinition[]>;
}>();

const isEdit = computed<boolean>(() => props.mode === 'edit');

const heading = computed<string>(() =>
  isEdit.value
    ? (props.segment?.name ?? t('i18n.pages.segments.form.segment'))
    : t('i18n.pages.segments.form.new_segment'),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

interface SegmentFormData {
  [key: string]: string | FilterGroupNode | null;
  name: string;
  object_type_id: string;
  filter_definition: FilterGroupNode | null;
}

const form = useForm<SegmentFormData>({
  name: props.segment?.name ?? '',
  object_type_id: props.segment?.object_type_id ?? props.objectTypeOptions[0]?.value ?? '',
  filter_definition: props.segment?.filter_definition ?? null,
});

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    name: form.name,
    objectTypeId: form.object_type_id,
    filterDefinition: form.filter_definition,
  }),
  backHref: SegmentManagementController.index.url(),
});

const fields = computed<FieldDefinition[]>(() => props.fieldsByType[form.object_type_id] ?? []);

const canEditFilter = computed<boolean>(() => fields.value.length > 0);

const objectTypeLabel = computed<string>(
  () => props.objectTypeOptions.find((option) => option.value === form.object_type_id)?.label ?? '',
);

const filterKey = computed<string>(() => form.object_type_id);

if (!isEdit.value) {
  watch(
    () => form.object_type_id,
    () => {
      form.filter_definition = null;
    },
  );
}

function onSubmit(): void {
  if (isEdit.value && props.segment !== null) {
    form
      .transform((data) => ({
        name: data.name,
        filter_definition: data.filter_definition,
      }))
      .put(
        SegmentManagementController.update.url({
          segment: props.segment.id,
        }),
        { preserveScroll: true, onSuccess: markSaved },
      );

    return;
  }

  form
    .transform((data) => ({
      name: data.name,
      object_type_id: data.object_type_id === '' ? null : data.object_type_id,
      filter_definition: data.filter_definition,
    }))
    .post(SegmentManagementController.store.url(), {
      preserveScroll: true,
      onSuccess: markSaved,
    });
}
</script>

<template>
  <PageShell
    :title="heading"
    :description="
      mode === 'create'
        ? t('i18n.pages.segments.form.create_a_new_segment_for_an_object_type')
        : t('i18n.pages.segments.form.edit_this_segment_s_name_and_filters')
    "
    :head-title="
      mode === 'create'
        ? t('i18n.pages.segments.form.new_segment')
        : t('i18n.pages.segments.form.edit_segment', {
            value1: segment?.name,
          })
    "
  >
    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.segments.form.details') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.segments.form.segments_are_available_as_filters_in_the_corresponding_object') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form class="flex flex-col gap-4" @submit.prevent="onSubmit">
          <div class="grid gap-4 sm:grid-cols-2">
            <FormField for="segment-name" :label="t('i18n.pages.segments.form.name')" :error="form.errors.name">
              <Input
                id="segment-name"
                v-model="form.name"
                :placeholder="t('i18n.pages.segments.form.segment_name')"
                required
              />
            </FormField>

            <FormField
              for="segment-object-type"
              :label="t('i18n.pages.segments.form.object_type')"
              :error="form.errors.object_type_id"
            >
              <Combobox
                v-if="!isEdit"
                id="segment-object-type"
                :model-value="form.object_type_id"
                :options="objectTypeOptions"
                :searchable="objectTypeOptions.length > 8"
                :placeholder="t('i18n.pages.segments.form.select_object_type')"
                @update:model-value="form.object_type_id = $event ?? ''"
              />
              <p v-else class="text-sm text-muted-foreground">
                {{ objectTypeLabel || '—' }}
              </p>
            </FormField>
          </div>

          <FormField
            v-if="canEditFilter"
            :label="t('i18n.pages.segments.form.filter')"
            :error="form.errors.filter_definition"
          >
            <FilterBuilder
              :key="filterKey"
              :fields="fields"
              :model-value="segment?.filter_definition ?? undefined"
              :show-actions="false"
              @update:model-value="form.filter_definition = $event"
            />
          </FormField>

          <p v-else class="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">
            {{ t('i18n.pages.segments.form.no_filterable_fields_are_configured_for_this_object_type') }}
          </p>

          <FormActions :dirty="isDirty" :processing="form.processing" @cancel="requestLeave" />
        </form>
      </CardContent>
    </Card>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
