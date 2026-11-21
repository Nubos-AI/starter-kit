<script setup lang="ts">
import { computed } from 'vue';
import { useCollapsedSections } from '@/shared/composables/useCollapsedSections';
import type { FieldDefinition } from '@/shared/types/fields';
import CollapsibleCardTitle from '@/shared/ui/CollapsibleCardTitle.vue';
import { Card, CardContent, CardDescription, CardHeader } from '@/shared/ui/primitives/card';
import { Collapsible, CollapsibleContent } from '@/shared/ui/primitives/collapsible';
import { useFieldTypeRegistry } from '../composables/useFieldTypeRegistry';
import type { FieldGroupRow } from '../types/fieldGroups';
import { buildFieldSections } from '../utils/fieldGrouping';
import type { FieldSection } from '../utils/fieldGrouping';
import DynamicField from './DynamicField.vue';

const props = withDefaults(
  defineProps<{
    fields: FieldDefinition[];
    creating?: boolean;
    groups?: FieldGroupRow[];
    errors?: Record<string, string>;
    objectTypeId?: string | null;
    hiddenSections?: string[];
  }>(),
  {
    creating: false,
    groups: () => [],
    errors: () => ({}),
    objectTypeId: null,
    hiddenSections: () => [],
  },
);

const values = defineModel<Record<string, unknown>>({
  default: () => ({}),
});

const { resolveWidget } = useFieldTypeRegistry();

const renderableFields = computed(() =>
  props.fields.filter((field) => {
    if (props.creating && ['computed', 'rollup'].includes(field.field_type)) {
      return false;
    }

    if (resolveWidget(field.field_type)) {
      return true;
    }

    return false;
  }),
);

const sections = computed<FieldSection[]>(() =>
  buildFieldSections(renderableFields.value, props.groups).filter(
    (section) => !props.hiddenSections.includes(section.id),
  ),
);

const { isOpen, setOpen } = useCollapsedSections(props.objectTypeId);

function sectionOpen(section: FieldSection): boolean {
  if (section.fields.some((field) => props.errors[field.key])) {
    return true;
  }

  return isOpen(section.id);
}

function fieldKeys(section: FieldSection): string[] {
  return section.fields.map((field) => field.key);
}

function setValue(key: string, value: unknown): void {
  values.value = { ...values.value, [key]: value };
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <Collapsible
      v-for="section in sections"
      :key="section.id"
      as-child
      :open="sectionOpen(section)"
      @update:open="setOpen(section.id, $event)"
    >
      <Card :data-field-section="section.id">
        <CardHeader>
          <CollapsibleCardTitle
            :title="section.label"
            :open="sectionOpen(section)"
            :data-field-section-toggle="section.id"
          />
          <CardDescription v-if="section.description !== null">
            {{ section.description }}
          </CardDescription>
        </CardHeader>

        <CollapsibleContent
          class="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down"
        >
          <CardContent class="flex flex-col gap-6">
            <DynamicField
              v-for="field in section.fields"
              :key="field.key"
              :field="field"
              :values="values"
              :model-value="values[field.key]"
              :error="errors?.[field.key]"
              @update:model-value="setValue(field.key, $event)"
            />

            <slot name="actions" :fields="fieldKeys(section)" />
          </CardContent>
        </CollapsibleContent>
      </Card>
    </Collapsible>
  </div>
</template>
