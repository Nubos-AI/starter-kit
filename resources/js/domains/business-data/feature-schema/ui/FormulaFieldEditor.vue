<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import type { FormulaResultType, ObjectTypeFieldRow } from '@/domains/business-data/util-record-format';
import { formulaResultTypeOptions, referenceableFields } from '@/domains/business-data/util-record-format';
import { useI18n } from '@/shared/composables/useI18n';
import { textareaElement } from '@/shared/lib/nativeElement';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import { Textarea } from '@/shared/ui/primitives/textarea';

const { t } = useI18n();

const props = defineProps<{
  fieldType: string;
  fields: ObjectTypeFieldRow[];
  currentKey?: string | null;
  initialFormula?: string | null;
  initialResultType?: FormulaResultType | null;
  errorMessage?: string;
}>();

const formula = ref<string>(props.initialFormula ?? '');
const resultType = ref<string>(props.initialResultType ?? '');
const formulaInput = useTemplateRef('formulaInput');

const offeredFields = computed<ObjectTypeFieldRow[]>(() => referenceableFields(props.fields, props.currentKey ?? null));

const formulaHint = t(
  'i18n.components.engine.object_type.formula_field_editor.refer_to_another_field_in_single_braces_separate_function',
);

function insertFieldKey(key: string): void {
  const input = textareaElement(formulaInput.value);
  const token = `{${key}}`;

  if (input === null) {
    formula.value += token;

    return;
  }

  const start = input.selectionStart;
  const end = input.selectionEnd;
  const caret = start + token.length;

  formula.value = formula.value.slice(0, start) + token + formula.value.slice(end);

  nextTick(() => {
    input.focus();
    input.setSelectionRange(caret, caret);
  });
}
</script>

<template>
  <div v-if="props.fieldType === 'computed'" class="grid gap-4">
    <FormField
      v-slot="{ describedBy }"
      for="field_formula"
      :label="t('i18n.components.engine.object_type.formula_field_editor.formula')"
      :hint="formulaHint"
      :error="props.errorMessage"
    >
      <Textarea
        id="field_formula"
        ref="formulaInput"
        v-model="formula"
        name="config[formula]"
        rows="3"
        spellcheck="false"
        :placeholder="t('i18n.components.engine.object_type.formula_field_editor.e_g_trim_first_name_last_name')"
        :aria-invalid="props.errorMessage ? true : undefined"
        :aria-describedby="describedBy"
        class="min-h-20 font-mono"
      />
    </FormField>

    <FormField
      for="field_result_type"
      :label="t('i18n.components.engine.object_type.formula_field_editor.result_type')"
      :hint="
        t('i18n.components.engine.object_type.formula_field_editor.the_result_type_decides_how_the_value_is_filtered')
      "
    >
      <Select v-model="resultType" name="config[result_type]" required>
        <SelectTrigger id="field_result_type" class="w-full">
          <SelectValue
            :placeholder="t('i18n.components.engine.object_type.formula_field_editor.select_a_result_type')"
          />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in formulaResultTypeOptions" :key="option.value" :value="option.value">
            {{ t(option.label) }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <div class="grid gap-2">
      <span id="field_formula_references" class="text-sm leading-none font-medium">
        {{ t('i18n.components.engine.object_type.formula_field_editor.insert_a_field_reference') }}
      </span>
      <div
        v-if="offeredFields.length > 0"
        role="group"
        aria-labelledby="field_formula_references"
        class="flex flex-wrap gap-1.5"
      >
        <Button
          v-for="field in offeredFields"
          :key="field.id"
          type="button"
          variant="outline"
          size="sm"
          :data-testid="`formula-field-${field.key}`"
          class="font-mono text-xs font-normal shadow-none"
          @mousedown.prevent
          @click="insertFieldKey(field.key)"
        >
          {{ field.key }}
        </Button>
      </div>
      <p v-else class="text-xs text-muted-foreground">
        {{
          t('i18n.components.engine.object_type.formula_field_editor.this_object_type_carries_no_other_field_a_formula')
        }}
      </p>
    </div>
  </div>
</template>
