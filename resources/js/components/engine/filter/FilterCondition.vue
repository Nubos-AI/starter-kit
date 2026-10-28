<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { MultiSelect } from '@/components/ui/multi-select';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    filterOperatorsFor,
    normalizeFieldOptions,
} from '@/composables/useFieldTypeRegistry';
import {
    effectiveArityFor,
    useFilterTreeContext,
} from '@/composables/useFilterTree';
import type {
    EffectiveArity,
    FilterTreeCondition,
} from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import type { FieldOption } from '@/types/fields';

const { t } = useI18n();

const props = defineProps<{
    node: FilterTreeCondition;
}>();

const context = useFilterTreeContext();

const fieldDefinition = computed(() =>
    context.fields.find((field) => field.key === props.node.field),
);

const operators = computed(() =>
    fieldDefinition.value
        ? filterOperatorsFor(fieldDefinition.value.field_type)
        : [],
);

const arity = computed<EffectiveArity>(() =>
    effectiveArityFor(context.fields, props.node.field, props.node.operator),
);

type InputValue = string | number | undefined;

const value = computed<InputValue>(() => props.node.value as InputValue);

const valueTo = computed<InputValue>(() => props.node.valueTo as InputValue);

const SEARCH_THRESHOLD = 8;

const fieldOptions = computed<FieldOption[]>(() =>
    normalizeFieldOptions(fieldDefinition.value?.config?.options),
);

const hasOptions = computed<boolean>(() => fieldOptions.value.length > 0);

const selectedValues = computed<string[]>(() => {
    const raw = props.node.value;

    if (Array.isArray(raw)) {
        return raw.map((entry) => String(entry));
    }

    return typeof raw === 'string' && raw !== '' ? [raw] : [];
});

const singleValue = computed<string | null>(() =>
    typeof props.node.value === 'string' && props.node.value !== ''
        ? props.node.value
        : null,
);

const multiValue = computed<string>(() => {
    const raw = props.node.value;

    if (Array.isArray(raw)) {
        return raw.join(', ');
    }

    return typeof raw === 'string' ? raw : '';
});

function onMultiValueChange(input: string | number): void {
    const parts = String(input)
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part.length > 0);

    context.updateCondition(props.node.id, { value: parts });
}

function onFieldChange(value: unknown): void {
    const key = (value as string) ?? '';
    const definition = context.fields.find((field) => field.key === key);
    const nextOperators = definition
        ? filterOperatorsFor(definition.field_type)
        : [];
    const stillValid = nextOperators.some(
        (option) => option.value === props.node.operator,
    );

    context.updateCondition(props.node.id, {
        field: key,
        operator: stillValid
            ? props.node.operator
            : (nextOperators[0]?.value ?? ''),
        value: undefined,
        valueTo: undefined,
    });
}

function onOperatorChange(value: unknown): void {
    const nextOperator = (value as string) ?? '';
    const previousArity = effectiveArityFor(
        context.fields,
        props.node.field,
        props.node.operator,
    );
    const nextArity = effectiveArityFor(
        context.fields,
        props.node.field,
        nextOperator,
    );

    if (previousArity === nextArity) {
        context.updateCondition(props.node.id, { operator: nextOperator });

        return;
    }

    context.updateCondition(props.node.id, {
        operator: nextOperator,
        value: nextArity === 'multi' ? [] : undefined,
        valueTo: undefined,
    });
}

function onValueChange(value: string | number): void {
    context.updateCondition(props.node.id, { value });
}

function onOptionValueChange(value: unknown): void {
    context.updateCondition(props.node.id, { value: value as string });
}

function onOptionValuesChange(values: string[]): void {
    context.updateCondition(props.node.id, { value: values });
}

function onValueToChange(value: string | number): void {
    context.updateCondition(props.node.id, { valueTo: value });
}
</script>

<template>
    <div
        data-filter-condition
        class="flex flex-wrap items-center gap-2 rounded-md border border-border bg-background px-3 py-2"
    >
        <Select :model-value="node.field" @update:model-value="onFieldChange">
            <SelectTrigger
                class="w-40"
                :aria-label="
                    t('i18n.components.engine.filter.filter_condition.field')
                "
            >
                <SelectValue
                    :placeholder="
                        t(
                            'i18n.components.engine.filter.filter_condition.select_field',
                        )
                    "
                />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem
                        v-for="field in context.fields"
                        :key="field.key"
                        :value="field.key"
                    >
                        {{ field.label }}
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>

        <Select
            :model-value="node.operator"
            @update:model-value="onOperatorChange"
        >
            <SelectTrigger
                class="w-44"
                :aria-label="
                    t('i18n.components.engine.filter.filter_condition.operator')
                "
            >
                <SelectValue
                    :placeholder="
                        t(
                            'i18n.components.engine.filter.filter_condition.select_operator',
                        )
                    "
                />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem
                        v-for="operator in operators"
                        :key="operator.value"
                        :value="operator.value"
                    >
                        {{ operator.label }}
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>

        <Combobox
            v-if="hasOptions && arity === 'single'"
            class="w-56"
            :model-value="singleValue"
            :options="fieldOptions"
            :searchable="fieldOptions.length > SEARCH_THRESHOLD"
            :placeholder="
                t('i18n.components.engine.filter.filter_condition.select_value')
            "
            :aria-label="
                t('i18n.components.engine.filter.filter_condition.value')
            "
            @update:model-value="onOptionValueChange"
        />

        <Input
            v-else-if="arity === 'single' || arity === 'range'"
            :model-value="value"
            :aria-label="
                t('i18n.components.engine.filter.filter_condition.value')
            "
            :placeholder="
                t('i18n.components.engine.filter.filter_condition.value')
            "
            class="w-40"
            @update:model-value="onValueChange"
        />
        <Input
            v-if="arity === 'range'"
            :model-value="valueTo"
            :aria-label="
                t('i18n.components.engine.filter.filter_condition.upper_value')
            "
            :placeholder="
                t('i18n.components.engine.filter.filter_condition.to')
            "
            class="w-40"
            @update:model-value="onValueToChange"
        />
        <MultiSelect
            v-if="hasOptions && arity === 'multi'"
            class="w-64"
            :model-value="selectedValues"
            :options="fieldOptions"
            :searchable="fieldOptions.length > SEARCH_THRESHOLD"
            :placeholder="
                t(
                    'i18n.components.engine.filter.filter_condition.select_values',
                )
            "
            :aria-label="
                t('i18n.components.engine.filter.filter_condition.values')
            "
            @update:model-value="onOptionValuesChange"
        />

        <Input
            v-else-if="arity === 'multi'"
            :model-value="multiValue"
            :aria-label="
                t('i18n.components.engine.filter.filter_condition.values')
            "
            :placeholder="
                t(
                    'i18n.components.engine.filter.filter_condition.value1_value2',
                )
            "
            class="w-56"
            @update:model-value="onMultiValueChange"
        />

        <Button
            type="button"
            variant="plain"
            size="icon-sm"
            class="ml-auto icon-danger hover:icon-danger-hovered"
            data-remove-node
            :aria-label="
                t(
                    'i18n.components.engine.filter.filter_condition.remove_condition',
                )
            "
            @click="context.removeNode(node.id)"
        >
            <Trash2 class="size-4" />
        </Button>
    </div>
</template>
