<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import AgingThresholdEditor from '@/components/engine/objectType/AgingThresholdEditor.vue';
import FormActions from '@/components/FormActions.vue';
import InputError from '@/components/InputError.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import { useModuleOptions } from '@/composables/useModuleOptions';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type {
    AgingClock,
    AgingRulePayload,
    AgingRuleRow,
    AgingThreshold,
} from '@/types/aging';
import { hasDuplicateDurations, sortThresholds } from '@/types/aging';
import type { FieldDefinition } from '@/types/fields';
import { hydratableFilterTree, normalizeFilterTree } from '@/types/reports';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const CREATE_TITLE = t(
    'i18n.components.engine.object_type.aging_rule_form.create_aging_rule',
);

const EDIT_TITLE = t(
    'i18n.components.engine.object_type.aging_rule_form.edit_aging_rule',
);

const SHEET_DESCRIPTION = t(
    'i18n.components.engine.object_type.aging_rule_form.clock_optional_condition_and_thresholds_for_this_rule',
);

const CLOCK_FIELD_HINT = t(
    'i18n.components.engine.object_type.aging_rule_form.only_date_fields_of_this_object_type_that_are',
);

const CLOCK_FIELD_EMPTY_HINT = t(
    'i18n.components.engine.object_type.aging_rule_form.this_object_type_has_no_date_fields_that_can',
);

const CONDITION_EMPTY_HINT = t(
    'i18n.components.engine.object_type.aging_rule_form.without_a_condition_the_rule_applies_to_all_records',
);

const DEFAULT_THRESHOLD: AgingThreshold = { after_days: 7, color: 'amber' };

const props = defineProps<{
    rule: AgingRuleRow | null;
    clockFieldOptions: SelectOption[];
    conditionFields: FieldDefinition[];
    activeRuleCount: number;
    activeRuleLimit: number;
    errors: Record<string, string>;
    processing: boolean;
}>();

const emit = defineEmits<{
    submit: [payload: AgingRulePayload];
    close: [];
}>();

const clockOptions = useModuleOptions('aging.clocks', [
    {
        value: 'updated_at',
        label: t(
            'i18n.components.engine.object_type.aging_rule_form.last_change',
        ),
    },
    {
        value: 'field',
        label: t(
            'i18n.components.engine.object_type.aging_rule_form.custom_date_field',
        ),
    },
]);

const name = ref<string>(props.rule?.name ?? '');
const clock = ref<AgingClock>(props.rule?.clock ?? 'updated_at');
const clockFieldKey = ref<string>(props.rule?.clock_field_key ?? '');
const conditionSeed = ref<FilterGroupNode | undefined>(
    hydratableFilterTree(props.rule?.condition),
);
const conditionTree = ref<FilterGroupNode | null>(conditionSeed.value ?? null);
const thresholds = ref<AgingThreshold[]>(
    props.rule === null
        ? [{ ...DEFAULT_THRESHOLD }]
        : props.rule.thresholds.map((threshold) => ({ ...threshold })),
);
const activeLimitReached = computed<boolean>(
    () =>
        props.rule?.is_active !== true &&
        props.activeRuleCount >= props.activeRuleLimit,
);

const isActive = ref<boolean>(
    props.rule?.is_active ?? !activeLimitReached.value,
);
const triggersAutomation = ref<boolean>(
    props.rule?.triggers_automation ?? false,
);

const closeRequested = ref<boolean>(false);

const controlId = useId();

function fieldId(suffix: string): string {
    return `${controlId}-${suffix}`;
}

const heading = computed<string>(() =>
    props.rule === null ? CREATE_TITLE : EDIT_TITLE,
);

const measuresField = computed<boolean>(() => clock.value === 'field');

const clockFieldMissing = computed<boolean>(
    () => measuresField.value && props.clockFieldOptions.length === 0,
);

const activeLimitHint = computed<string>(() =>
    t(
        'i18n.components.engine.object_type.aging_rule_form.this_object_type_already_has_the_maximum_supported_number',
        { value1: props.activeRuleLimit },
    ),
);

const hasDuplicateThresholds = computed<boolean>(() =>
    hasDuplicateDurations(thresholds.value),
);

const { isDirty, promptOpen, confirmLeave, cancelLeave } = useUnsavedChanges({
    values: () => ({
        name: name.value,
        clock: clock.value,
        clockFieldKey: clockFieldKey.value,
        conditionTree: conditionTree.value,
        thresholds: thresholds.value,
        isActive: isActive.value,
        triggersAutomation: triggersAutomation.value,
    }),
    backHref: () => ObjectTypesController.index.url(),
});

const canSubmit = computed<boolean>(
    () =>
        isDirty.value &&
        !hasDuplicateThresholds.value &&
        !clockFieldMissing.value,
);

function onClockChange(value: unknown): void {
    const next = clockOptions.value.find(
        (entry) => entry.value === String(value),
    )?.value;

    if (next === undefined) {
        return;
    }

    clock.value = next;
}

function onConditionChange(tree: FilterGroupNode): void {
    conditionTree.value = normalizeFilterTree(tree);
}

function buildPayload(): AgingRulePayload {
    return {
        name: name.value.trim(),
        clock: clock.value,
        clock_field_key:
            measuresField.value && clockFieldKey.value !== ''
                ? clockFieldKey.value
                : null,
        condition: conditionTree.value,
        thresholds: sortThresholds(thresholds.value),
        is_active: isActive.value,
        triggers_automation: triggersAutomation.value,
    };
}

function onSubmit(): void {
    if (!canSubmit.value) {
        return;
    }

    emit('submit', buildPayload());
}

function onCancel(): void {
    if (isDirty.value) {
        closeRequested.value = true;

        return;
    }

    emit('close');
}

function onConfirmLeave(): void {
    if (closeRequested.value) {
        closeRequested.value = false;
        emit('close');

        return;
    }

    confirmLeave();
}

function onCancelLeave(): void {
    closeRequested.value = false;
    cancelLeave();
}

function onOpenChange(next: boolean): void {
    if (!next) {
        onCancel();
    }
}
</script>

<template>
    <div>
        <Sheet :open="true" @update:open="onOpenChange">
            <SheetContent side="right" class="w-full sm:max-w-xl">
                <div
                    data-aging-form
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4"
                >
                    <SheetHeader class="px-0">
                        <SheetTitle>{{ heading }}</SheetTitle>
                        <SheetDescription>
                            {{ SHEET_DESCRIPTION }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="grid gap-2">
                        <Label :for="fieldId('name')">{{
                            t(
                                'i18n.components.engine.object_type.aging_rule_form.name',
                            )
                        }}</Label>
                        <Input
                            :id="fieldId('name')"
                            v-model="name"
                            name="name"
                        />
                        <InputError :message="props.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="fieldId('clock')">{{
                            t(
                                'i18n.components.engine.object_type.aging_rule_form.clock',
                            )
                        }}</Label>
                        <Select
                            :model-value="clock"
                            name="clock"
                            @update:model-value="onClockChange"
                        >
                            <SelectTrigger
                                :id="fieldId('clock')"
                                class="w-full"
                            >
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.components.engine.object_type.aging_rule_form.select_clock',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in clockOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="props.errors.clock" />
                    </div>

                    <div
                        v-if="measuresField"
                        class="grid gap-2"
                        data-aging-clock-field
                    >
                        <template v-if="props.clockFieldOptions.length > 0">
                            <Label :for="fieldId('clock_field_key')">{{
                                t(
                                    'i18n.components.engine.object_type.aging_rule_form.date_field',
                                )
                            }}</Label>
                            <Select
                                v-model="clockFieldKey"
                                name="clock_field_key"
                            >
                                <SelectTrigger
                                    :id="fieldId('clock_field_key')"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.components.engine.object_type.aging_rule_form.select_date_field',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in props.clockFieldOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                {{ CLOCK_FIELD_HINT }}
                            </p>
                        </template>
                        <p
                            v-else
                            class="text-sm text-muted-foreground"
                            data-aging-clock-field-empty
                        >
                            {{ CLOCK_FIELD_EMPTY_HINT }}
                        </p>
                        <InputError :message="props.errors.clock_field_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label :id="fieldId('condition')">{{
                            t(
                                'i18n.components.engine.object_type.aging_rule_form.condition',
                            )
                        }}</Label>
                        <p
                            v-if="conditionTree === null"
                            class="text-xs text-muted-foreground"
                            data-aging-condition-empty
                        >
                            {{ CONDITION_EMPTY_HINT }}
                        </p>
                        <div
                            role="group"
                            :aria-labelledby="fieldId('condition')"
                        >
                            <FilterBuilder
                                :fields="props.conditionFields"
                                :model-value="conditionSeed"
                                :show-actions="false"
                                @update:model-value="onConditionChange"
                            />
                        </div>
                        <InputError :message="props.errors.condition" />
                    </div>

                    <div class="grid gap-2">
                        <Label :id="fieldId('thresholds')">{{
                            t(
                                'i18n.components.engine.object_type.aging_rule_form.thresholds',
                            )
                        }}</Label>
                        <div
                            role="group"
                            :aria-labelledby="fieldId('thresholds')"
                        >
                            <AgingThresholdEditor
                                v-model="thresholds"
                                :errors="props.errors"
                            />
                        </div>
                        <InputError :message="props.errors.thresholds" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                :id="fieldId('is_active')"
                                v-model="isActive"
                                :disabled="activeLimitReached"
                                data-aging-active-toggle
                            />
                            <Label
                                :for="fieldId('is_active')"
                                class="font-normal"
                            >
                                {{
                                    t(
                                        'i18n.components.engine.object_type.aging_rule_form.active',
                                    )
                                }}
                            </Label>
                        </div>
                        <p
                            v-if="activeLimitReached"
                            class="text-xs text-muted-foreground"
                            data-aging-active-limit
                        >
                            {{ activeLimitHint }}
                        </p>
                        <InputError :message="props.errors.is_active" />
                    </div>

                    <UiExtensionPoint
                        name="aging.rule-form"
                        :context="{
                            value: triggersAutomation,
                            update: (value: boolean) =>
                                (triggersAutomation = value),
                            id: fieldId('triggers_automation'),
                            error: props.errors.triggers_automation,
                        }"
                    />

                    <FormActions
                        type="button"
                        :dirty="canSubmit"
                        :processing="props.processing"
                        @cancel="onCancel"
                        @save="onSubmit"
                    />
                </div>
            </SheetContent>
        </Sheet>

        <UnsavedChangesDialog
            :open="promptOpen || closeRequested"
            @confirm="onConfirmLeave"
            @cancel="onCancelLeave"
        />
    </div>
</template>
