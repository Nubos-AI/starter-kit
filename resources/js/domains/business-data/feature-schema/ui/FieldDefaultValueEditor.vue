<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import { numberInputFormat } from '@/shared/lib/localizedNumber';
import { fieldInputType } from '@/shared/record-format/utils/fieldTypeCatalog';
import type { FieldDefaultKind, FieldDefaultSource, FieldDefaultValue } from '@/shared/types/fieldEditor';
import {
  fieldDefaultKindOptions,
  fieldDefaultSourceOptions,
  isNumericField,
  supportsDefaultValue,
} from '@/shared/types/fieldEditor';
import type { FieldType } from '@/shared/types/fields';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Input } from '@/shared/ui/primitives/input';
import { NumberInput } from '@/shared/ui/primitives/number-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';

const { t } = useI18n();

const props = defineProps<{
  fieldType: FieldType;
  initialDefault: FieldDefaultValue | null;
  errorMessage?: string;
}>();

const controlId = useId();

const kind = ref<FieldDefaultKind>(props.initialDefault?.kind ?? 'none');
const staticValue = ref<string>(
  props.initialDefault?.value === undefined || props.initialDefault?.value === null
    ? ''
    : String(props.initialDefault.value),
);
const source = ref<FieldDefaultSource>(props.initialDefault?.source ?? 'today');

const isVisible = computed<boolean>(() => supportsDefaultValue(props.fieldType));

const inputType = computed<string>(() => fieldInputType(props.fieldType));

const isNumeric = computed<boolean>(() => isNumericField(props.fieldType));
</script>

<template>
  <div v-if="isVisible" data-testid="field-default-editor" class="grid gap-4">
    <FormField
      :for="`${controlId}_kind`"
      :label="t('i18n.components.engine.object_type.field_default_value_editor.default_value')"
    >
      <Select v-model="kind">
        <SelectTrigger :id="`${controlId}_kind`" class="w-full">
          <SelectValue
            :placeholder="t('i18n.components.engine.object_type.field_default_value_editor.select_default_value')"
          />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in fieldDefaultKindOptions" :key="option.value" :value="option.value">
            {{ t(option.label) }}
          </SelectItem>
        </SelectContent>
      </Select>
      <input v-if="kind !== 'none'" type="hidden" name="default_value[kind]" :value="kind" />
    </FormField>

    <FormField
      v-if="kind === 'static'"
      :for="`${controlId}_value`"
      :label="t('i18n.components.engine.object_type.field_default_value_editor.fixed_value')"
      :hint="
        t(
          'i18n.components.engine.object_type.field_default_value_editor.this_value_is_inserted_on_creation_if_the_field',
        )
      "
    >
      <NumberInput
        v-if="isNumeric"
        :id="`${controlId}_value`"
        v-model="staticValue"
        name="default_value[value]"
        :format="numberInputFormat(fieldType)"
        data-testid="default-static-value"
      />
      <Input
        v-else
        :id="`${controlId}_value`"
        v-model="staticValue"
        name="default_value[value]"
        :type="inputType"
        data-testid="default-static-value"
      />
    </FormField>

    <FormField
      v-if="kind === 'dynamic'"
      :for="`${controlId}_source`"
      :label="t('i18n.components.engine.object_type.field_default_value_editor.source')"
      :hint="
        t(
          'i18n.components.engine.object_type.field_default_value_editor.the_value_is_generated_when_the_record_is_created',
        )
      "
    >
      <Select v-model="source" name="default_value[source]">
        <SelectTrigger :id="`${controlId}_source`" class="w-full">
          <SelectValue
            :placeholder="t('i18n.components.engine.object_type.field_default_value_editor.select_source')"
          />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in fieldDefaultSourceOptions" :key="option.value" :value="option.value">
            {{ t(option.label) }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <InputError :message="props.errorMessage" />
  </div>
</template>
