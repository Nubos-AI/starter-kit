<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import GoalsController from '@/actions/App/Http/Controllers/Goals/GoalsController';
import FormActions from '@/components/FormActions.vue';
import GoalPeriodHistory from '@/components/goals/GoalPeriodHistory.vue';
import GoalProgressCard from '@/components/goals/GoalProgressCard.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type {
    GoalDirection,
    GoalListRow,
    GoalPeriodType,
    GoalScopeType,
} from '@/types/goals';
import {
    GOAL_DIRECTION_HINTS,
    GOAL_PERIOD_SNAPSHOT_LABEL,
    GOAL_SCOPE_FIELD_DEFAULTS,
    resolveGoalOptionRefusal,
} from '@/types/goals';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const SEARCHABLE_THRESHOLD = 8;

const SCOPE_FIELD_DEFAULT_KEYS = Object.values(GOAL_SCOPE_FIELD_DEFAULTS);

const props = defineProps<{
    mode: 'create' | 'edit';
    goal: GoalListRow | null;
    reportOptions: SelectOption[];
    scopeFieldsByReport: Record<string, SelectOption[]>;
    periodFieldsByReport: Record<string, SelectOption[]>;
    userOptions: SelectOption[];
    teamOptions: SelectOption[];
    scopeTypeOptions: SelectOption[];
    periodTypeOptions: SelectOption[];
    directionOptions: SelectOption[];
}>();

const isEdit = computed<boolean>(() => props.mode === 'edit');

const heading = computed<string>(() =>
    isEdit.value
        ? (props.goal?.name ?? t('i18n.pages.goals.form.goal'))
        : t('i18n.pages.goals.form.new_goal'),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const name = ref<string>(props.goal?.name ?? '');
const reportId = ref<string | null>(props.goal?.report_id ?? null);
const scopeType = ref<GoalScopeType>(props.goal?.scope_type ?? 'user');
const targetUserId = ref<string | null>(props.goal?.target_user_id ?? null);
const targetTeamId = ref<string | null>(props.goal?.target_team_id ?? null);
const includesSubteams = ref<boolean>(props.goal?.includes_subteams ?? false);
const scopeFieldKey = ref<string | null>(props.goal?.scope_field_key ?? null);
const periodFieldKey = ref<string | null>(props.goal?.period_field_key ?? '');
const periodType = ref<GoalPeriodType>(props.goal?.period_type ?? 'month');
const direction = ref<GoalDirection>(props.goal?.direction ?? 'at_least');
const targetValue = ref<string>(props.goal?.target_value ?? '');

const form = useForm<Record<string, string>>({});

const isTeamScope = computed<boolean>(() => scopeType.value === 'team');

const isTenantScope = computed<boolean>(() => scopeType.value === 'tenant');

const sourceOptions = computed<SelectOption[]>(() =>
    props.reportOptions.map((option) => ({
        ...option,
        disabledReason: resolveGoalOptionRefusal(option.disabledReason),
    })),
);

const targetOptions = computed<SelectOption[]>(() =>
    isTeamScope.value ? props.teamOptions : props.userOptions,
);

const targetId = computed<string | null>({
    get: () => (isTeamScope.value ? targetTeamId.value : targetUserId.value),
    set: (value) => {
        if (isTeamScope.value) {
            targetTeamId.value = value;

            return;
        }

        targetUserId.value = value;
    },
});

const scopeFieldOptions = computed<SelectOption[]>(
    () => props.scopeFieldsByReport[reportId.value ?? ''] ?? [],
);

const periodFieldOptions = computed<SelectOption[]>(() => [
    { value: '', label: GOAL_PERIOD_SNAPSHOT_LABEL },
    ...(props.periodFieldsByReport[reportId.value ?? ''] ?? []),
]);

const directionHint = computed<string>(
    () => GOAL_DIRECTION_HINTS[direction.value],
);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => snapshot(),
    backHref: GoalsController.index.url(),
});

watch(scopeType, (next) => {
    if (next === 'tenant') {
        targetUserId.value = null;
        targetTeamId.value = null;
        includesSubteams.value = false;
        scopeFieldKey.value = null;

        return;
    }

    prefillScopeField(next);
});

watch(reportId, () => {
    const followedScope = followsScope(scopeFieldKey.value);

    if (!isOffered(scopeFieldOptions.value, scopeFieldKey.value)) {
        scopeFieldKey.value = null;
    }

    if (!isOffered(periodFieldOptions.value, periodFieldKey.value)) {
        periodFieldKey.value = '';
    }

    if (followedScope) {
        prefillScopeField(scopeType.value);
    }
});

function isOffered(options: SelectOption[], value: string | null): boolean {
    return options.some((option) => option.value === value);
}

function followsScope(value: string | null): boolean {
    const current = blankToNull(value);

    return current === null || SCOPE_FIELD_DEFAULT_KEYS.includes(current);
}

function prefillScopeField(scope: GoalScopeType): void {
    if (scope === 'tenant' || !followsScope(scopeFieldKey.value)) {
        return;
    }

    const fallback = GOAL_SCOPE_FIELD_DEFAULTS[scope];

    if (isOffered(scopeFieldOptions.value, fallback)) {
        scopeFieldKey.value = fallback;
    }
}

function blankToNull(value: string | null): string | null {
    return value === null || value === '' ? null : value;
}

function snapshot(): Record<string, unknown> {
    return {
        name: name.value,
        reportId: reportId.value,
        scopeType: scopeType.value,
        targetUserId: targetUserId.value,
        targetTeamId: targetTeamId.value,
        includesSubteams: includesSubteams.value,
        scopeFieldKey: scopeFieldKey.value,
        periodFieldKey: periodFieldKey.value,
        periodType: periodType.value,
        direction: direction.value,
        targetValue: targetValue.value,
    };
}

function submitPayload(): Record<string, unknown> {
    return {
        name: name.value,
        report_id: blankToNull(reportId.value),
        scope_type: scopeType.value,
        target_user_id: blankToNull(targetUserId.value),
        target_team_id: blankToNull(targetTeamId.value),
        includes_subteams: includesSubteams.value,
        scope_field_key: blankToNull(scopeFieldKey.value),
        period_field_key: blankToNull(periodFieldKey.value),
        period_type: periodType.value,
        direction: direction.value,
        target_value: targetValue.value,
    };
}

function onSubteamsChange(checked: boolean | 'indeterminate'): void {
    includesSubteams.value = checked === true;
}

function onSaved(): void {
    markSaved();
    toast.success(t('i18n.pages.goals.form.the_goal_was_saved'));
}

function onSubmit(): void {
    const goal = props.goal;

    if (isEdit.value && goal !== null) {
        form.transform(() => submitPayload()).put(
            GoalsController.update.url({ goal: goal.id }),
            { preserveScroll: true, onSuccess: onSaved },
        );

        return;
    }

    form.transform(() => submitPayload()).post(GoalsController.store.url(), {
        preserveScroll: true,
        onSuccess: onSaved,
    });
}
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.goals.form.new_goal')
                : t('i18n.pages.goals.form.edit_goal', {
                      value1: goal?.name,
                  })
        "
    />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="heading"
            :description="
                mode === 'create'
                    ? t(
                          'i18n.pages.goals.form.create_a_new_goal_based_on_a_report',
                      )
                    : t('i18n.pages.goals.form.edit_this_goal_s_definition')
            "
        />

        <form class="flex flex-col gap-6" @submit.prevent="onSubmit">
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.goals.form.basic_information')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.goals.form.the_name_appears_in_the_list_and_above_the',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:max-w-sm" data-goal-field="name">
                        <Label for="goal-name">{{
                            t('i18n.pages.goals.form.name')
                        }}</Label>
                        <Input
                            id="goal-name"
                            v-model="name"
                            :placeholder="t('i18n.pages.goals.form.goal_name')"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.goals.form.source')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.goals.form.a_goal_measures_exactly_one_metric_reports_with_grouping',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div
                        class="grid gap-2 sm:max-w-sm"
                        data-goal-field="report"
                    >
                        <Label for="goal-report">{{
                            t('i18n.pages.goals.form.report')
                        }}</Label>
                        <Combobox
                            id="goal-report"
                            v-model="reportId"
                            :options="sourceOptions"
                            :searchable="
                                sourceOptions.length > SEARCHABLE_THRESHOLD
                            "
                            :placeholder="
                                t('i18n.pages.goals.form.select_report')
                            "
                            :empty-label="
                                t('i18n.pages.goals.form.no_reports_available')
                            "
                        />
                        <InputError :message="form.errors.report_id" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.goals.form.assignment')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.goals.form.the_assignment_determines_whose_numbers_the_goal_measures_and',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid gap-2" data-goal-field="scope-type">
                        <Label for="goal-scope-type">{{
                            t('i18n.pages.goals.form.applies_to')
                        }}</Label>
                        <Select v-model="scopeType">
                            <SelectTrigger id="goal-scope-type" class="w-full">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.goals.form.select_assignment',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in scopeTypeOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.scope_type" />
                    </div>

                    <div
                        v-if="!isTenantScope"
                        class="grid gap-2"
                        data-goal-field="target"
                    >
                        <Label for="goal-target">
                            {{
                                isTeamScope
                                    ? t('i18n.pages.goals.form.team')
                                    : t('i18n.pages.goals.form.person')
                            }}
                        </Label>
                        <Combobox
                            id="goal-target"
                            v-model="targetId"
                            :options="targetOptions"
                            :searchable="
                                targetOptions.length > SEARCHABLE_THRESHOLD
                            "
                            :placeholder="
                                isTeamScope
                                    ? t('i18n.pages.goals.form.select_team')
                                    : t('i18n.pages.goals.form.select_person')
                            "
                            :empty-label="
                                t('i18n.pages.goals.form.no_options_available')
                            "
                        />
                        <InputError
                            :message="
                                isTeamScope
                                    ? form.errors.target_team_id
                                    : form.errors.target_user_id
                            "
                        />
                    </div>

                    <div
                        v-if="!isTenantScope"
                        class="grid gap-2"
                        data-goal-field="scope-field"
                    >
                        <Label for="goal-scope-field">
                            {{ t('i18n.pages.goals.form.restricting_field') }}
                        </Label>
                        <Combobox
                            id="goal-scope-field"
                            v-model="scopeFieldKey"
                            :options="scopeFieldOptions"
                            :searchable="
                                scopeFieldOptions.length > SEARCHABLE_THRESHOLD
                            "
                            :placeholder="
                                t('i18n.pages.goals.form.select_field')
                            "
                            :empty-label="
                                t(
                                    'i18n.pages.goals.form.please_select_a_report_first',
                                )
                            "
                        />
                        <InputError :message="form.errors.scope_field_key" />
                    </div>

                    <div
                        v-if="isTeamScope"
                        class="grid gap-2"
                        data-goal-field="subteams"
                    >
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="goal-subteams"
                                :model-value="includesSubteams"
                                @update:model-value="onSubteamsChange"
                            />
                            <Label for="goal-subteams">
                                {{
                                    t('i18n.pages.goals.form.include_subteams')
                                }}
                            </Label>
                        </div>
                        <InputError :message="form.errors.includes_subteams" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.goals.form.period')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.goals.form.the_time_reference_determines_whether_the_metric_is_limited',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid gap-2" data-goal-field="period-field">
                        <Label for="goal-period-field">{{
                            t('i18n.pages.goals.form.time_reference')
                        }}</Label>
                        <Combobox
                            id="goal-period-field"
                            v-model="periodFieldKey"
                            :options="periodFieldOptions"
                            :searchable="
                                periodFieldOptions.length > SEARCHABLE_THRESHOLD
                            "
                            :placeholder="
                                t('i18n.pages.goals.form.select_time_reference')
                            "
                        />
                        <InputError :message="form.errors.period_field_key" />
                    </div>

                    <div class="grid gap-2" data-goal-field="period-type">
                        <Label for="goal-period-type">{{
                            t('i18n.pages.goals.form.period_type')
                        }}</Label>
                        <Select v-model="periodType">
                            <SelectTrigger id="goal-period-type" class="w-full">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.goals.form.select_period_type',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in periodTypeOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.period_type" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('i18n.pages.goals.form.goal') }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.goals.form.the_direction_and_target_value_determine_when_the_goal',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid gap-2" data-goal-field="direction">
                        <Label for="goal-direction">{{
                            t('i18n.pages.goals.form.direction')
                        }}</Label>
                        <Select v-model="direction">
                            <SelectTrigger id="goal-direction" class="w-full">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.goals.form.select_direction',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in directionOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            {{ directionHint }}
                        </p>
                        <InputError :message="form.errors.direction" />
                    </div>

                    <div class="grid gap-2" data-goal-field="target-value">
                        <Label for="goal-target-value">{{
                            t('i18n.pages.goals.form.target_value')
                        }}</Label>
                        <Input
                            id="goal-target-value"
                            v-model="targetValue"
                            type="number"
                            step="any"
                            :placeholder="t('i18n.pages.goals.form.e_g_100000')"
                            required
                        />
                        <InputError :message="form.errors.target_value" />
                    </div>
                </CardContent>
            </Card>

            <template v-if="isEdit && goal !== null">
                <GoalProgressCard :goal="goal" />
                <GoalPeriodHistory :goal="goal" />
            </template>

            <FormActions
                :dirty="isDirty"
                :processing="form.processing"
                @cancel="requestLeave"
            />
        </form>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
