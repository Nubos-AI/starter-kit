<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Input } from '@/shared/ui/primitives/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import FilterBuilder from '../../../../shared/filters/ui/FilterBuilder.vue';
import type { RollupFieldConfig, RollupScope, RollupTargetOption } from '../types/rollups';
import { rollupAggregateOptions, rollupScopeOptions } from '../types/rollups';

const { t } = useI18n();

interface HiddenFilterInput {
  name: string;
  value: string;
}

const props = defineProps<{
  fieldType: string;
  rollupTargets: RollupTargetOption[];
  initialConfig: RollupFieldConfig | null;
  errorMessage?: string;
}>();

const controlId = useId();

const noHierarchyHint = t(
  'i18n.components.engine.object_type.rollup_field_editor.the_selected_relationship_points_at_an_object_type_without',
);

const emptyTree: FilterGroupNode = { combinator: 'and', conditions: [] };

const aggregate = ref<string>(props.initialConfig?.aggregate ?? 'sum');
const relationshipTypeId = ref<string>(props.initialConfig?.relationship_type_id ?? '');
const sourceFieldKey = ref<string>(props.initialConfig?.source_field_key ?? '');
const scope = ref<RollupScope>(props.initialConfig?.scope ?? 'direct_children');
const filter = ref<FilterGroupNode>(props.initialConfig?.filter ?? emptyTree);

const selectedTarget = computed<RollupTargetOption | null>(
  () => props.rollupTargets.find((target) => target.value === relationshipTypeId.value) ?? null,
);

const targetFields = computed<FieldDefinition[]>(() => selectedTarget.value?.fields ?? []);

const subtreeAvailable = computed<boolean>(() => selectedTarget.value?.has_hierarchy === true);

const filterInputs = computed<HiddenFilterInput[]>(() => {
  if (filter.value.conditions.length === 0) {
    return [];
  }

  const inputs: HiddenFilterInput[] = [];

  collectInputs(filter.value, 'config[filter]', inputs);

  return inputs;
});

function collectInputs(node: FilterGroupNode, prefix: string, inputs: HiddenFilterInput[]): void {
  inputs.push({ name: `${prefix}[combinator]`, value: node.combinator });

  node.conditions.forEach((child, index) => {
    const childPrefix = `${prefix}[conditions][${index}]`;

    if ('combinator' in child) {
      collectInputs(child, childPrefix, inputs);

      return;
    }

    inputs.push({ name: `${childPrefix}[field]`, value: child.field });
    inputs.push({
      name: `${childPrefix}[operator]`,
      value: child.operator,
    });

    if (Array.isArray(child.value)) {
      child.value.forEach((entry, valueIndex) => {
        inputs.push({
          name: `${childPrefix}[value][${valueIndex}]`,
          value: String(entry),
        });
      });
    } else if (child.value !== undefined && child.value !== null) {
      inputs.push({
        name: `${childPrefix}[value]`,
        value: String(child.value),
      });
    }

    if (child.valueTo !== undefined && child.valueTo !== null) {
      inputs.push({
        name: `${childPrefix}[valueTo]`,
        value: String(child.valueTo),
      });
    }
  });
}

watch(relationshipTypeId, () => {
  if (!subtreeAvailable.value) {
    scope.value = 'direct_children';
  }

  filter.value = emptyTree;
});
</script>

<template>
  <div
    v-if="props.fieldType === 'rollup'"
    data-testid="rollup-config"
    :aria-invalid="props.errorMessage ? true : undefined"
    class="grid gap-4"
  >
    <FormField
      :for="`${controlId}_aggregate`"
      :label="t('i18n.components.engine.object_type.rollup_field_editor.aggregation')"
    >
      <Select v-model="aggregate" name="config[aggregate]">
        <SelectTrigger :id="`${controlId}_aggregate`" class="w-full">
          <SelectValue :placeholder="t('i18n.components.engine.object_type.rollup_field_editor.select_an_aggregate')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in rollupAggregateOptions" :key="option.value" :value="option.value">
            {{ t(option.label) }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <FormField
      :for="`${controlId}_relationship`"
      :label="t('i18n.components.engine.object_type.rollup_field_editor.relationship')"
    >
      <Select v-model="relationshipTypeId" name="config[relationship_type_id]">
        <SelectTrigger :id="`${controlId}_relationship`" class="w-full">
          <SelectValue
            :placeholder="t('i18n.components.engine.object_type.rollup_field_editor.select_a_relationship_type')"
          />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="target in props.rollupTargets" :key="target.value" :value="target.value">
            {{ target.label }}
          </SelectItem>
        </SelectContent>
      </Select>
      <EmptyState
        v-if="props.rollupTargets.length === 0"
        variant="inline"
        :description="
          t(
            'i18n.components.engine.object_type.rollup_field_editor.this_object_type_has_no_relationship_type_pointing_at',
          )
        "
      />
    </FormField>

    <FormField
      :for="`${controlId}_source_field`"
      :label="t('i18n.components.engine.object_type.rollup_field_editor.aggregated_field_key')"
      :hint="t('i18n.components.engine.object_type.rollup_field_editor.the_key_of_the_field_on_the_related_records')"
    >
      <Input
        :id="`${controlId}_source_field`"
        v-model="sourceFieldKey"
        name="config[source_field_key]"
        :list="`${controlId}_source_field_options`"
        :placeholder="t('i18n.components.engine.object_type.rollup_field_editor.e_g_amount')"
        autocomplete="off"
        spellcheck="false"
        class="font-mono"
      />
      <datalist :id="`${controlId}_source_field_options`">
        <option v-for="field in targetFields" :key="field.key" :value="field.key">
          {{ field.label }}
        </option>
      </datalist>
    </FormField>

    <FormField
      :for="`${controlId}_scope`"
      :label="t('i18n.components.engine.object_type.rollup_field_editor.aggregation_scope')"
    >
      <Select v-model="scope" name="config[scope]">
        <SelectTrigger :id="`${controlId}_scope`" class="w-full">
          <SelectValue :placeholder="t('i18n.components.engine.object_type.rollup_field_editor.select_a_scope')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="option in rollupScopeOptions"
            :key="option.value"
            :value="option.value"
            :disabled="option.value === 'subtree' && !subtreeAvailable"
          >
            {{ t(option.label) }}
          </SelectItem>
        </SelectContent>
      </Select>
      <p v-if="selectedTarget === null" class="text-xs text-muted-foreground">
        {{
          t(
            'i18n.components.engine.object_type.rollup_field_editor.select_a_relationship_type_to_choose_the_aggregation_scope',
          )
        }}
      </p>
      <p v-else-if="!subtreeAvailable" class="text-xs text-muted-foreground">
        {{ noHierarchyHint }}
      </p>
    </FormField>

    <div class="grid gap-2">
      <span class="text-sm leading-none font-medium">
        {{ t('i18n.components.engine.object_type.rollup_field_editor.filter_condition_optional') }}
      </span>
      <FilterBuilder :key="relationshipTypeId" v-model="filter" :fields="targetFields" :show-actions="false" />
      <input v-for="input in filterInputs" :key="input.name" type="hidden" :name="input.name" :value="input.value" />
      <p class="text-xs text-muted-foreground">
        {{
          t(
            'i18n.components.engine.object_type.rollup_field_editor.only_related_records_matching_every_condition_are_aggregated',
          )
        }}
      </p>
    </div>

    <InputError :message="props.errorMessage" />
  </div>
</template>
