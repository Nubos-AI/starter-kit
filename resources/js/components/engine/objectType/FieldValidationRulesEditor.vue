<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import type {
    FieldCrossOperator,
    FieldValidationRules,
} from '@/types/fieldEditor';
import {
    fieldCrossOperatorOptions,
    isNumericField,
    isTemporalField,
    supportsValidationRules,
} from '@/types/fieldEditor';
import type { FieldType } from '@/types/fields';
import type { ObjectTypeFieldRow } from '@/types/formulas';

const { t } = useI18n();

const props = defineProps<{
    fieldType: FieldType;
    fields: ObjectTypeFieldRow[];
    currentKey: string | null;
    initialRules: FieldValidationRules | null;
    errorMessage?: string;
}>();

const controlId = useId();

const noCrossOperator = 'none';

const initialCross = Object.entries(props.initialRules?.cross ?? {})[0] ?? null;

const regex = ref<string>(props.initialRules?.regex ?? '');
const min = ref<string>(
    props.initialRules?.min === undefined || props.initialRules?.min === null
        ? ''
        : String(props.initialRules.min),
);
const max = ref<string>(
    props.initialRules?.max === undefined || props.initialRules?.max === null
        ? ''
        : String(props.initialRules.max),
);
const allowedValues = ref<string>((props.initialRules?.in ?? []).join(', '));
const crossOperator = ref<FieldCrossOperator | typeof noCrossOperator>(
    (initialCross?.[0] as FieldCrossOperator | undefined) ?? noCrossOperator,
);
const crossTarget = ref<string>(initialCross?.[1] ?? '');

const isVisible = computed<boolean>(() =>
    supportsValidationRules(props.fieldType),
);

const isTemporal = computed<boolean>(() => isTemporalField(props.fieldType));

const isNumeric = computed<boolean>(() => isNumericField(props.fieldType));

const boundInputType = computed<string>(() => {
    if (isNumeric.value) {
        return 'number';
    }

    if (props.fieldType === 'date') {
        return 'date';
    }

    if (props.fieldType === 'datetime') {
        return 'datetime-local';
    }

    return 'number';
});

const boundHint = computed<string>(() =>
    isTemporal.value
        ? t(
              'i18n.components.engine.object_type.field_validation_rules_editor.earliest_and_latest_allowed_date',
          )
        : isNumeric.value
          ? t(
                'i18n.components.engine.object_type.field_validation_rules_editor.lowest_and_highest_allowed_value',
            )
          : t(
                'i18n.components.engine.object_type.field_validation_rules_editor.minimum_and_maximum_number_of_characters',
            ),
);

const crossCandidates = computed<ObjectTypeFieldRow[]>(() =>
    props.fields.filter((field) => field.key !== props.currentKey),
);

const submittedValues = computed<string[]>(() =>
    allowedValues.value
        .split(',')
        .map((value) => value.trim())
        .filter((value) => value !== ''),
);

const hasCross = computed<boolean>(
    () => crossOperator.value !== noCrossOperator && crossTarget.value !== '',
);
</script>

<template>
    <div
        v-if="isVisible"
        data-testid="field-validation-editor"
        class="grid gap-4"
    >
        <span class="text-sm leading-none font-medium">{{
            t(
                'i18n.components.engine.object_type.field_validation_rules_editor.validation_rules',
            )
        }}</span>

        <div v-if="!isTemporal && !isNumeric" class="grid gap-2">
            <Label :for="`${controlId}_regex`">{{
                t(
                    'i18n.components.engine.object_type.field_validation_rules_editor.pattern_regular_expression',
                )
            }}</Label>
            <Input
                :id="`${controlId}_regex`"
                v-model="regex"
                name="validation_rules[regex]"
                :placeholder="
                    t(
                        'i18n.components.engine.object_type.field_validation_rules_editor.e_g_a_z_2_d',
                    )
                "
                autocomplete="off"
                spellcheck="false"
                class="font-mono"
                data-testid="validation-regex"
            />
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div class="grid gap-2">
                <Label :for="`${controlId}_min`">{{
                    t(
                        'i18n.components.engine.object_type.field_validation_rules_editor.minimum',
                    )
                }}</Label>
                <Input
                    :id="`${controlId}_min`"
                    v-model="min"
                    name="validation_rules[min]"
                    :type="boundInputType"
                    data-testid="validation-min"
                />
            </div>
            <div class="grid gap-2">
                <Label :for="`${controlId}_max`">{{
                    t(
                        'i18n.components.engine.object_type.field_validation_rules_editor.maximum',
                    )
                }}</Label>
                <Input
                    :id="`${controlId}_max`"
                    v-model="max"
                    name="validation_rules[max]"
                    :type="boundInputType"
                    data-testid="validation-max"
                />
            </div>
        </div>
        <p class="text-xs text-muted-foreground">{{ boundHint }}</p>

        <div class="grid gap-2">
            <Label :for="`${controlId}_in`">{{
                t(
                    'i18n.components.engine.object_type.field_validation_rules_editor.allowed_values',
                )
            }}</Label>
            <Input
                :id="`${controlId}_in`"
                v-model="allowedValues"
                :placeholder="
                    t(
                        'i18n.components.engine.object_type.field_validation_rules_editor.comma_separated_e_g_red_green_blue',
                    )
                "
                data-testid="validation-in"
            />
            <input
                v-for="(value, index) in submittedValues"
                :key="`allowed-${index}`"
                type="hidden"
                :name="`validation_rules[in][${index}]`"
                :value="value"
            />
        </div>

        <div class="grid gap-2">
            <Label :for="`${controlId}_cross`">{{
                t(
                    'i18n.components.engine.object_type.field_validation_rules_editor.compare_with_another_field',
                )
            }}</Label>
            <div class="grid grid-cols-2 gap-2">
                <Select v-model="crossOperator">
                    <SelectTrigger :id="`${controlId}_cross`" class="w-full">
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.components.engine.object_type.field_validation_rules_editor.no_comparison',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="noCrossOperator">
                            {{
                                t(
                                    'i18n.components.engine.object_type.field_validation_rules_editor.no_comparison',
                                )
                            }}
                        </SelectItem>
                        <SelectItem
                            v-for="option in fieldCrossOperatorOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select
                    v-model="crossTarget"
                    :disabled="crossOperator === noCrossOperator"
                >
                    <SelectTrigger class="w-full">
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.components.engine.object_type.field_validation_rules_editor.select_field',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="field in crossCandidates"
                            :key="field.key"
                            :value="field.key"
                        >
                            {{ field.label || field.key }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <input
                v-if="hasCross"
                type="hidden"
                :name="`validation_rules[cross][${crossOperator}]`"
                :value="crossTarget"
            />
        </div>

        <InputError :message="props.errorMessage" />
    </div>
</template>
