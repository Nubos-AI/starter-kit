<script setup lang="ts">
import type { FormDataType } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MergeRulesController from '@/actions/App/Http/Controllers/Engine/MergeRulesController';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import { useModuleOptions } from '@/composables/useModuleOptions';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { FieldDefinition } from '@/types/fields';
import type {
    MergeFieldStrategy,
    MergeFieldStrategyOption,
    MergeRuleMode,
    MergeRuleObjectType,
    MergeRuleOption,
    MergeRulePayload,
    MergeRuleRow,
    MergeTransferCategoryOption,
    MergeTransferPolicy,
} from '@/types/merge';
import {
    MERGE_FIELD_STRATEGY_LABELS,
    MERGE_RULE_MODE_LABELS,
    MERGE_RULE_MODES,
    MERGE_RULE_OPTION_LABELS,
    MERGE_RULE_OPTIONS,
    MERGE_TRANSFER_CATEGORY_LABELS,
    MERGE_TRANSFER_POLICY_LABELS,
} from '@/types/merge';
import { hydratableFilterTree, normalizeFilterTree } from '@/types/reports';

const { t } = useI18n();

const CREATE_TITLE = t(
    'i18n.pages.object_types.merge_rules.form.create_merge_rule',
);

const EDIT_TITLE = t(
    'i18n.pages.object_types.merge_rules.form.edit_merge_rule',
);

const PAGE_DESCRIPTION = t(
    'i18n.pages.object_types.merge_rules.form.rules_are_checked_in_ascending_order_the_first_match',
);

const SECTION_TITLE = t('i18n.pages.object_types.merge_rules.form.merge_rules');

const INHERIT_VALUE = '__inherit';

const INHERIT_LABEL = t('i18n.pages.object_types.merge_rules.form.default');

const BASICS_TITLE = t(
    'i18n.pages.object_types.merge_rules.form.basic_information',
);

const BASICS_DESCRIPTION = t(
    'i18n.pages.object_types.merge_rules.form.name_effect_and_the_position_at_which_this_rule',
);

const CONDITION_TITLE = t('i18n.pages.object_types.merge_rules.form.condition');

const CONDITION_DESCRIPTION = t(
    'i18n.pages.object_types.merge_rules.form.without_a_condition_the_rule_applies_to_all_records',
);

const MODE_HINT_ALLOW = t(
    'i18n.pages.object_types.merge_rules.form.the_rule_allows_merging_and_determines_which_value_wins',
);

const MODE_HINT_DENY = t(
    'i18n.pages.object_types.merge_rules.form.the_rule_prevents_merging_it_applies_when_either_record',
);

const STRATEGIES_TITLE = t(
    'i18n.pages.object_types.merge_rules.form.field_resolution',
);

const STRATEGIES_DESCRIPTION = t(
    'i18n.pages.object_types.merge_rules.form.which_value_wins_when_both_records_contain_a_value',
);

const STRATEGY_EMPTY_HINT = t(
    'i18n.pages.object_types.merge_rules.form.this_object_type_has_no_fields_for_which_a',
);

const TRANSFERS_TITLE = t(
    'i18n.pages.object_types.merge_rules.form.what_moves_with_the_record',
);

const TRANSFERS_DESCRIPTION = t(
    'i18n.pages.object_types.merge_rules.form.for_each_category_move_to_the_target_leave_at',
);

const OPTIONS_TITLE = t(
    'i18n.pages.object_types.merge_rules.form.additional_conditions',
);

const OPTIONS_DESCRIPTION = t(
    'i18n.pages.object_types.merge_rules.form.switches_that_further_restrict_merging',
);

const props = defineProps<{
    objectType: MergeRuleObjectType;
    mergeRule: MergeRuleRow | null;
    mergeConditionFields: FieldDefinition[];
    mergeFieldStrategyOptions: MergeFieldStrategyOption[];
    mergeTransferCategories: MergeTransferCategoryOption[];
    mergeActiveRuleLimit: number;
    activeRuleCount: number;
}>();

const listHref = computed<string>(() =>
    ObjectTypesController.mergeRules.url({
        objectType: props.objectType.slug,
    }),
);

const heading = computed<string>(() =>
    props.mergeRule === null ? CREATE_TITLE : EDIT_TITLE,
);

usePageBreadcrumbs(() => [
    {
        title: props.objectType.name,
        href: ObjectTypesController.edit({ objectType: props.objectType.slug }),
    },
    {
        title: SECTION_TITLE,
        href: ObjectTypesController.mergeRules({
            objectType: props.objectType.slug,
        }),
    },
    { title: heading.value },
]);

const modeOptions = MERGE_RULE_MODES.map((mode) => ({
    value: mode,
    label: MERGE_RULE_MODE_LABELS[mode],
}));

const name = ref<string>(props.mergeRule?.name ?? '');
const mode = ref<MergeRuleMode>(props.mergeRule?.mode ?? 'allow');
const position = ref<string>(String(props.mergeRule?.position ?? 0));
const denyReason = ref<string>(props.mergeRule?.deny_reason ?? '');
const conditionSeed = ref<FilterGroupNode | undefined>(
    hydratableFilterTree(props.mergeRule?.condition),
);
const conditionTree = ref<FilterGroupNode | null>(conditionSeed.value ?? null);

const fieldStrategies = ref<Record<string, string>>(
    Object.fromEntries(
        props.mergeFieldStrategyOptions.map((option) => [
            option.key,
            props.mergeRule?.field_strategies[option.key] ?? INHERIT_VALUE,
        ]),
    ),
);

const transferPolicies = ref<Record<string, string>>(
    Object.fromEntries(
        props.mergeTransferCategories.map((category) => [
            category.value,
            props.mergeRule?.transfer_policy[category.value] ?? INHERIT_VALUE,
        ]),
    ),
);

const mergeOptions = useModuleOptions(
    'merge.options',
    MERGE_RULE_OPTIONS.map((value) => ({
        value,
        label: MERGE_RULE_OPTION_LABELS[value],
    })),
);

const options = ref<Record<string, boolean>>(
    Object.fromEntries(
        mergeOptions.value.map((option) => [
            option.value,
            props.mergeRule?.options[option.value] ?? false,
        ]),
    ),
);

const activeLimitReached = computed<boolean>(
    () =>
        props.mergeRule?.is_active !== true &&
        props.activeRuleCount >= props.mergeActiveRuleLimit,
);

const isActive = ref<boolean>(
    props.mergeRule?.is_active ?? !activeLimitReached.value,
);

const errors = ref<Record<string, string>>({});
const processing = ref<boolean>(false);

const forbids = computed<boolean>(() => mode.value === 'deny');

const modeHint = computed<string>(() =>
    forbids.value ? MODE_HINT_DENY : MODE_HINT_ALLOW,
);

const hasStrategyFields = computed<boolean>(
    () => props.mergeFieldStrategyOptions.length > 0,
);

const activeLimitHint = computed<string>(() =>
    t(
        'i18n.pages.object_types.merge_rules.form.this_object_type_already_has_the_maximum_supported_number',
        { value1: props.mergeActiveRuleLimit },
    ),
);

const positionValue = computed<number>(() => {
    const parsed = Number.parseInt(position.value, 10);

    return Number.isFinite(parsed) && parsed >= 0 ? parsed : 0;
});

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        name: name.value,
        mode: mode.value,
        position: position.value,
        denyReason: denyReason.value,
        conditionTree: conditionTree.value,
        fieldStrategies: fieldStrategies.value,
        transferPolicies: transferPolicies.value,
        options: options.value,
        isActive: isActive.value,
    }),
    backHref: () => listHref.value,
});

const canSubmit = computed<boolean>(
    () =>
        isDirty.value &&
        name.value.trim() !== '' &&
        (!forbids.value || denyReason.value.trim() !== ''),
);

function onModeChange(value: unknown): void {
    const next = MERGE_RULE_MODES.find((entry) => entry === String(value));

    if (next === undefined) {
        return;
    }

    mode.value = next;
}

function onConditionChange(tree: FilterGroupNode): void {
    conditionTree.value = normalizeFilterTree(tree);
}

function strategyLabel(strategy: MergeFieldStrategy): string {
    return MERGE_FIELD_STRATEGY_LABELS[strategy];
}

function policyLabel(policy: MergeTransferPolicy): string {
    return MERGE_TRANSFER_POLICY_LABELS[policy];
}

function categoryLabel(category: MergeTransferCategoryOption): string {
    return MERGE_TRANSFER_CATEGORY_LABELS[category.value];
}

function chosenMap<T extends string>(
    source: Record<string, string>,
): Record<string, T> {
    const chosen: Record<string, T> = {};

    for (const [key, value] of Object.entries(source)) {
        if (value !== INHERIT_VALUE) {
            chosen[key] = value as T;
        }
    }

    return chosen;
}

function payload(): MergeRulePayload {
    const forbidding = forbids.value;

    return {
        name: name.value.trim(),
        mode: mode.value,
        position: positionValue.value,
        is_active: isActive.value,
        deny_reason: forbidding ? denyReason.value.trim() : null,
        condition: conditionTree.value,
        field_strategies: forbidding
            ? {}
            : chosenMap<MergeFieldStrategy>(fieldStrategies.value),
        transfer_policy: forbidding
            ? {}
            : chosenMap<MergeTransferPolicy>(transferPolicies.value),
        options: forbidding
            ? {}
            : (options.value as Partial<Record<MergeRuleOption, boolean>>),
    };
}

function onSubmit(): void {
    if (!canSubmit.value) {
        return;
    }

    const rule = props.mergeRule;

    const writeOptions = {
        onStart: (): void => {
            processing.value = true;
        },
        onError: (received: Record<string, string>): void => {
            errors.value = received;
        },
        onSuccess: (): void => {
            errors.value = {};
            markSaved();
        },
        onFinish: (): void => {
            processing.value = false;
        },
    };

    if (rule === null) {
        router.post<FormDataType<MergeRulePayload>>(
            MergeRulesController.store.url({
                objectType: props.objectType.slug,
            }),
            payload(),
            writeOptions,
        );

        return;
    }

    router.put<FormDataType<MergeRulePayload>>(
        MergeRulesController.update.url({
            objectType: props.objectType.slug,
            mergeRule: rule.id,
        }),
        payload(),
        writeOptions,
    );
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head :title="`${objectType.name} — ${heading}`" />

        <Heading
            variant="small"
            :title="heading"
            :description="PAGE_DESCRIPTION"
        />

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ BASICS_TITLE }}</CardTitle>
                    <CardDescription>{{ BASICS_DESCRIPTION }}</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label for="merge-rule-name">{{
                            t('i18n.pages.object_types.merge_rules.form.name')
                        }}</Label>
                        <Input
                            id="merge-rule-name"
                            v-model="name"
                            name="name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="merge-rule-mode">{{
                                t(
                                    'i18n.pages.object_types.merge_rules.form.effect',
                                )
                            }}</Label>
                            <Select
                                :model-value="mode"
                                name="mode"
                                @update:model-value="onModeChange"
                            >
                                <SelectTrigger
                                    id="merge-rule-mode"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.object_types.merge_rules.form.select_effect',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in modeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.mode" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="merge-rule-position">{{
                                t(
                                    'i18n.pages.object_types.merge_rules.form.order',
                                )
                            }}</Label>
                            <Input
                                id="merge-rule-position"
                                v-model="position"
                                name="position"
                                type="number"
                                min="0"
                            />
                            <InputError :message="errors.position" />
                        </div>
                    </div>

                    <p
                        class="text-xs text-muted-foreground"
                        data-merge-mode-hint
                    >
                        {{ modeHint }}
                    </p>

                    <div
                        v-if="forbids"
                        class="grid gap-2"
                        data-merge-deny-reason
                    >
                        <Label for="merge-rule-deny-reason">{{
                            t('i18n.pages.object_types.merge_rules.form.reason')
                        }}</Label>
                        <Input
                            id="merge-rule-deny-reason"
                            v-model="denyReason"
                            name="deny_reason"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.object_types.merge_rules.form.this_text_appears_in_the_menu_and_wizard_when',
                                )
                            }}
                        </p>
                        <InputError :message="errors.deny_reason" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-3">
                            <Label for="merge-rule-active" class="font-normal">
                                {{
                                    t(
                                        'i18n.pages.object_types.merge_rules.form.active',
                                    )
                                }}
                            </Label>
                            <Switch
                                id="merge-rule-active"
                                v-model="isActive"
                                class="shrink-0"
                                :disabled="activeLimitReached"
                                data-merge-active-toggle
                            />
                        </div>
                        <p
                            v-if="activeLimitReached"
                            class="text-xs text-muted-foreground"
                            data-merge-active-limit
                        >
                            {{ activeLimitHint }}
                        </p>
                        <InputError :message="errors.is_active" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ CONDITION_TITLE }}</CardTitle>
                    <CardDescription>
                        {{ CONDITION_DESCRIPTION }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div
                        role="group"
                        :aria-label="
                            t(
                                'i18n.pages.object_types.merge_rules.form.condition',
                            )
                        "
                    >
                        <FilterBuilder
                            :fields="props.mergeConditionFields"
                            :model-value="conditionSeed"
                            :show-actions="false"
                            @update:model-value="onConditionChange"
                        />
                    </div>
                    <InputError :message="errors.condition" />
                </CardContent>
            </Card>

            <template v-if="!forbids">
                <Card data-merge-strategies>
                    <CardHeader>
                        <CardTitle>{{ STRATEGIES_TITLE }}</CardTitle>
                        <CardDescription>
                            {{ STRATEGIES_DESCRIPTION }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="!hasStrategyFields"
                            class="text-sm text-muted-foreground"
                            data-merge-strategies-empty
                        >
                            {{ STRATEGY_EMPTY_HINT }}
                        </p>
                        <div
                            v-else
                            class="grid items-start gap-4 sm:grid-cols-2"
                        >
                            <div
                                v-for="option in props.mergeFieldStrategyOptions"
                                :key="option.key"
                                class="grid gap-2"
                            >
                                <Label
                                    :for="`merge-strategy-${option.key}`"
                                    class="font-normal"
                                >
                                    {{ option.label }}
                                </Label>
                                <Select v-model="fieldStrategies[option.key]">
                                    <SelectTrigger
                                        :id="`merge-strategy-${option.key}`"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="INHERIT_VALUE">
                                            {{ INHERIT_LABEL }}
                                        </SelectItem>
                                        <SelectItem
                                            v-for="strategy in option.strategies"
                                            :key="strategy"
                                            :value="strategy"
                                        >
                                            {{ strategyLabel(strategy) }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <InputError :message="errors.field_strategies" />
                    </CardContent>
                </Card>

                <Card data-merge-transfers>
                    <CardHeader>
                        <CardTitle>{{ TRANSFERS_TITLE }}</CardTitle>
                        <CardDescription>
                            {{ TRANSFERS_DESCRIPTION }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid items-start gap-4 sm:grid-cols-2">
                            <div
                                v-for="category in props.mergeTransferCategories"
                                :key="category.value"
                                class="grid gap-2"
                            >
                                <Label
                                    :for="`merge-transfer-${category.value}`"
                                    class="font-normal"
                                >
                                    {{ categoryLabel(category) }}
                                </Label>
                                <Select
                                    v-model="transferPolicies[category.value]"
                                    :disabled="category.policies.length < 2"
                                >
                                    <SelectTrigger
                                        :id="`merge-transfer-${category.value}`"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="INHERIT_VALUE">
                                            {{ INHERIT_LABEL }} ({{
                                                policyLabel(
                                                    category.default_policy,
                                                )
                                            }})
                                        </SelectItem>
                                        <SelectItem
                                            v-for="policy in category.policies"
                                            :key="policy"
                                            :value="policy"
                                        >
                                            {{ policyLabel(policy) }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <InputError :message="errors.transfer_policy" />
                    </CardContent>
                </Card>

                <Card data-merge-options>
                    <CardHeader>
                        <CardTitle>{{ OPTIONS_TITLE }}</CardTitle>
                        <CardDescription>
                            {{ OPTIONS_DESCRIPTION }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2">
                        <div
                            v-for="option in mergeOptions"
                            :key="option.value"
                            class="flex items-center gap-3"
                        >
                            <Label
                                :for="`merge-option-${option.value}`"
                                class="font-normal"
                            >
                                {{ option.label }}
                            </Label>
                            <Switch
                                :id="`merge-option-${option.value}`"
                                v-model="options[option.value]"
                                class="shrink-0"
                                :data-merge-option="option.value"
                            />
                        </div>
                        <InputError :message="errors.options" />
                    </CardContent>
                </Card>
            </template>
        </div>

        <FormActions
            type="button"
            :dirty="canSubmit"
            :processing="processing"
            @cancel="requestLeave"
            @save="onSubmit"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
