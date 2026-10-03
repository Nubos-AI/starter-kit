<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronUp,
    Plus,
    TriangleAlert,
    Trash2,
} from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import AnchorApprovalDefinitionsController from '@/actions/App/Http/Controllers/Approvals/AnchorApprovalDefinitionsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CandidateCircleEditor from '@/components/engine/objectType/CandidateCircleEditor.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { useI18n } from '@/composables/useI18n';
import type {
    ApprovalAnchorKey,
    ApprovalDefinitionFormValue,
    ApprovalEscalationKey,
    ApprovalExclusionValue,
    ApprovalQuorumKey,
    ApprovalStageValue,
    CandidateCircleValue,
} from '@/types/approvalDefinitions';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const CARD_TITLE = t(
    'i18n.components.engine.object_type.approval_definition_card.approval',
);

const CARD_DESCRIPTION = t(
    'i18n.components.engine.object_type.approval_definition_card.when_approval_is_enabled_this_transition_only_occurs_after',
);

const ACTIVE_LABEL = t(
    'i18n.components.engine.object_type.approval_definition_card.approval_required',
);

const REJECTION_LABEL = t(
    'i18n.components.engine.object_type.approval_definition_card.rejection_transition',
);

const REJECTION_HINT = t(
    'i18n.components.engine.object_type.approval_definition_card.if_approval_is_rejected_the_record_takes_this_transition',
);

const EXCLUSIONS_TITLE = t(
    'i18n.components.engine.object_type.approval_definition_card.exclusion_reasons',
);

const EXCLUSIONS_HINT = t(
    'i18n.components.engine.object_type.approval_definition_card.anyone_excluded_for_an_active_reason_cannot_approve_this',
);

const STAGES_TITLE = t(
    'i18n.components.engine.object_type.approval_definition_card.stages',
);

const STAGES_HINT = t(
    'i18n.components.engine.object_type.approval_definition_card.stages_run_strictly_in_order_the_next_stage_only',
);

const STAGE_ADD_LABEL = t(
    'i18n.components.engine.object_type.approval_definition_card.add_level',
);

const QUORUM_LABEL = t(
    'i18n.components.engine.object_type.approval_definition_card.quorum',
);

const QUORUM_COUNT_LABEL = t(
    'i18n.components.engine.object_type.approval_definition_card.minimum_count',
);

const DEADLINE_LABEL = t(
    'i18n.components.engine.object_type.approval_definition_card.deadline_in_hours',
);

const ESCALATION_LABEL = t(
    'i18n.components.engine.object_type.approval_definition_card.when_the_deadline_expires',
);

const CIRCLE_TITLE = t(
    'i18n.components.engine.object_type.approval_definition_card.candidate_pool',
);

const ESCALATION_CIRCLE_TITLE = t(
    'i18n.components.engine.object_type.approval_definition_card.extended_pool',
);

const WARNINGS_TITLE = t(
    'i18n.components.engine.object_type.approval_definition_card.please_review_this_configuration',
);

const REMOVAL_TITLE = t(
    'i18n.components.engine.object_type.approval_definition_card.remove_approval',
);

const REMOVAL_DESCRIPTION = t(
    'i18n.components.engine.object_type.approval_definition_card.after_removal_this_transition_no_longer_requires_approval_existing',
);

const REMOVAL_CONFIRM_DESCRIPTION = t(
    'i18n.components.engine.object_type.approval_definition_card.are_you_sure_you_want_to_remove_approval_for',
);

const ANCHOR_TITLES: Record<ApprovalAnchorKey, string> = {
    promotion: t(
        'i18n.components.engine.object_type.approval_definition_card.approval_for_promotions',
    ),
};

const ANCHOR_DESCRIPTIONS: Record<ApprovalAnchorKey, string> = {
    promotion: t(
        'i18n.components.engine.object_type.approval_definition_card.when_approval_is_enabled_a_promotion_only_runs_after',
    ),
};

const ANCHOR_REMOVAL_DESCRIPTION = t(
    'i18n.components.engine.object_type.approval_definition_card.after_removal_this_operation_no_longer_requires_approval_existing',
);

const ANCHOR_REMOVAL_CONFIRM_DESCRIPTION = t(
    'i18n.components.engine.object_type.approval_definition_card.are_you_sure_you_want_to_remove_approval_for_2',
);

const NO_SELECTION = '__none';

const QUORUM_OPTIONS: SelectOption[] = [
    {
        value: 'any',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.one_approval_is_sufficient',
        ),
    },
    {
        value: 'at_least_n',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.minimum_number_of_approvals',
        ),
    },
    {
        value: 'all',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.everyone_must_approve',
        ),
    },
];

const ESCALATION_OPTIONS: SelectOption[] = [
    {
        value: NO_SELECTION,
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.do_nothing',
        ),
    },
    {
        value: 'delegate',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.forward_to_the_deputy',
        ),
    },
    {
        value: 'widen_circle',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.expand_candidate_pool',
        ),
    },
    {
        value: 'notify_again',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.notify_again',
        ),
    },
];

const EXCLUSION_ROWS: Array<{
    key: keyof ApprovalExclusionValue;
    label: string;
}> = [
    {
        key: 'trigger',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.process_initiator',
        ),
    },
    {
        key: 'last_editor',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.last_editor_of_a_checked_field',
        ),
    },
    {
        key: 'creator',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.record_creator',
        ),
    },
    {
        key: 'owner',
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.record_owner',
        ),
    },
];

const props = withDefaults(
    defineProps<{
        warnings: string[];
        roleOptions: SelectOption[];
        teamOptions: SelectOption[];
        userOptions: SelectOption[];
        fieldOptions?: SelectOption[];
        rejectionEdgeOptions?: SelectOption[];
        removalUrl?: string;
        anchorKind?: ApprovalAnchorKey | null;
        existing?: boolean;
        errors?: Record<string, string>;
    }>(),
    {
        fieldOptions: () => [],
        rejectionEdgeOptions: () => [],
        removalUrl: '',
        anchorKind: null,
        existing: false,
        errors: () => ({}),
    },
);

const model = defineModel<ApprovalDefinitionFormValue>({ required: true });

const controlId = useId();

const removalOpen = ref<boolean>(false);

const isActive = computed<boolean>({
    get: () => model.value.is_active,
    set: (value: boolean): void => patch({ is_active: value }),
});

const rejectionEdge = computed<string>({
    get: () => model.value.rejection_stage_transition_id ?? NO_SELECTION,
    set: (value: string): void =>
        patch({
            rejection_stage_transition_id:
                value === NO_SELECTION ? null : value,
        }),
});

const rejectionOptions = computed<SelectOption[]>(() => [
    {
        value: NO_SELECTION,
        label: t(
            'i18n.components.engine.object_type.approval_definition_card.no_transition',
        ),
    },
    ...props.rejectionEdgeOptions,
]);

const isAnchorMode = computed<boolean>(() => props.anchorKind !== null);

const cardTitle = computed<string>(() =>
    props.anchorKind === null ? CARD_TITLE : ANCHOR_TITLES[props.anchorKind],
);

const cardDescription = computed<string>(() =>
    props.anchorKind === null
        ? CARD_DESCRIPTION
        : ANCHOR_DESCRIPTIONS[props.anchorKind],
);

const removalDescription = computed<string>(() =>
    isAnchorMode.value ? ANCHOR_REMOVAL_DESCRIPTION : REMOVAL_DESCRIPTION,
);

const removalConfirmDescription = computed<string>(() =>
    isAnchorMode.value
        ? ANCHOR_REMOVAL_CONFIRM_DESCRIPTION
        : REMOVAL_CONFIRM_DESCRIPTION,
);

const circleFieldOptions = computed<SelectOption[]>(() =>
    isAnchorMode.value ? [] : props.fieldOptions,
);

function patch(changes: Partial<ApprovalDefinitionFormValue>): void {
    model.value = { ...model.value, ...changes };
}

function setExclusion(key: keyof ApprovalExclusionValue, value: boolean): void {
    patch({ exclusions: { ...model.value.exclusions, [key]: value } });
}

function updateStage(
    index: number,
    changes: Partial<ApprovalStageValue>,
): void {
    patch({
        stages: model.value.stages.map((stage, current) =>
            current === index ? { ...stage, ...changes } : stage,
        ),
    });
}

function setQuorumType(index: number, value: unknown): void {
    if (typeof value !== 'string') {
        return;
    }

    const quorumType = value as ApprovalQuorumKey;

    updateStage(index, {
        quorum_type: quorumType,
        quorum_count:
            quorumType === 'at_least_n'
                ? (model.value.stages[index].quorum_count ?? 2)
                : null,
    });
}

function setEscalationType(index: number, value: unknown): void {
    if (typeof value !== 'string') {
        return;
    }

    const escalationType =
        value === NO_SELECTION ? null : (value as ApprovalEscalationKey);

    updateStage(index, {
        escalation_type: escalationType,
        escalation_sources:
            escalationType === 'widen_circle'
                ? (model.value.stages[index].escalation_sources ??
                  emptyCircle())
                : null,
    });
}

function setWholeNumber(
    index: number,
    key: 'quorum_count' | 'deadline_hours',
    value: string | number,
): void {
    const parsed = Number(value);

    updateStage(index, {
        [key]: value === '' || Number.isNaN(parsed) ? null : parsed,
    });
}

function addStage(): void {
    patch({ stages: [...model.value.stages, emptyStage()] });
}

function removeStage(index: number): void {
    patch({
        stages: model.value.stages.filter((_, current) => current !== index),
    });
}

function moveStage(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= model.value.stages.length) {
        return;
    }

    const stages = [...model.value.stages];
    const [moved] = stages.splice(index, 1);

    stages.splice(target, 0, moved);
    patch({ stages });
}

function emptyCircle(): CandidateCircleValue {
    return {
        sources: [],
        role_ids: [],
        team_ids: [],
        include_record_team: false,
        field_key: null,
        user_ids: [],
    };
}

function emptyStage(): ApprovalStageValue {
    return {
        quorum_type: 'any',
        quorum_count: null,
        deadline_hours: null,
        escalation_type: null,
        escalation_sources: null,
        candidate_sources: emptyCircle(),
    };
}

function definitionRemovalUrl(): string {
    const kind = props.anchorKind;

    if (kind === null) {
        return props.removalUrl;
    }

    return AnchorApprovalDefinitionsController.destroy.url({ kind });
}

function removeDefinition(): void {
    removalOpen.value = false;

    router.delete(definitionRemovalUrl(), { preserveScroll: true });
}
</script>

<template>
    <div class="contents">
        <Card>
            <CardHeader>
                <CardTitle>{{ cardTitle }}</CardTitle>
                <CardDescription>{{ cardDescription }}</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <Label :for="`${controlId}_active`">
                        {{ ACTIVE_LABEL }}
                    </Label>
                    <Switch
                        :id="`${controlId}_active`"
                        v-model="isActive"
                        data-approval-active-switch
                    />
                </div>

                <div v-if="!isAnchorMode" class="grid gap-2 sm:max-w-sm">
                    <Label :for="`${controlId}_rejection`">
                        {{ REJECTION_LABEL }}
                    </Label>
                    <Select v-model="rejectionEdge">
                        <SelectTrigger
                            :id="`${controlId}_rejection`"
                            class="w-full"
                        >
                            <SelectValue
                                :placeholder="
                                    t(
                                        'i18n.components.engine.object_type.approval_definition_card.select_transition',
                                    )
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in rejectionOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ REJECTION_HINT }}
                    </p>
                    <InputError
                        :message="props.errors.rejection_stage_transition_id"
                    />
                </div>

                <div v-if="!isAnchorMode" class="grid gap-2">
                    <span class="text-sm leading-none font-medium">
                        {{ EXCLUSIONS_TITLE }}
                    </span>
                    <div
                        v-for="row in EXCLUSION_ROWS"
                        :key="row.key"
                        class="flex items-center gap-3"
                    >
                        <Label :for="`${controlId}_exclusion_${row.key}`">
                            {{ row.label }}
                        </Label>
                        <Switch
                            :id="`${controlId}_exclusion_${row.key}`"
                            :model-value="model.exclusions[row.key]"
                            :data-approval-exclusion="row.key"
                            @update:model-value="
                                (value: boolean) => setExclusion(row.key, value)
                            "
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ EXCLUSIONS_HINT }}
                    </p>
                </div>

                <Alert v-if="props.warnings.length > 0" data-approval-warnings>
                    <TriangleAlert />
                    <AlertTitle>{{ WARNINGS_TITLE }}</AlertTitle>
                    <AlertDescription>
                        <p v-for="warning in props.warnings" :key="warning">
                            {{ warning }}
                        </p>
                    </AlertDescription>
                </Alert>

                <div class="grid gap-3">
                    <span class="text-sm leading-none font-medium">
                        {{ STAGES_TITLE }}
                    </span>

                    <div
                        v-for="(stage, index) in model.stages"
                        :key="index"
                        class="grid gap-4 rounded-md border border-input p-3"
                        :data-stage="index"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium">
                                {{
                                    t(
                                        'i18n.components.engine.object_type.approval_definition_card.level',
                                    )
                                }}
                                {{ index + 1 }}
                            </span>
                            <div class="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :disabled="index === 0"
                                    :aria-label="
                                        t(
                                            'i18n.components.engine.object_type.approval_definition_card.move_stage_up',
                                            { value1: index + 1 },
                                        )
                                    "
                                    @click="moveStage(index, -1)"
                                >
                                    <ChevronUp />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :disabled="
                                        index === model.stages.length - 1
                                    "
                                    :aria-label="
                                        t(
                                            'i18n.components.engine.object_type.approval_definition_card.move_stage_down',
                                            { value1: index + 1 },
                                        )
                                    "
                                    @click="moveStage(index, 1)"
                                >
                                    <ChevronDown />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        t(
                                            'i18n.components.engine.object_type.approval_definition_card.remove_stage',
                                            { value1: index + 1 },
                                        )
                                    "
                                    :data-stage-remove="index"
                                    @click="removeStage(index)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label :for="`${controlId}_quorum_${index}`">
                                    {{ QUORUM_LABEL }}
                                </Label>
                                <Select
                                    :model-value="stage.quorum_type"
                                    @update:model-value="
                                        (value) => setQuorumType(index, value)
                                    "
                                >
                                    <SelectTrigger
                                        :id="`${controlId}_quorum_${index}`"
                                        class="w-full"
                                    >
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'i18n.components.engine.object_type.approval_definition_card.select_quorum',
                                                )
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in QUORUM_OPTIONS"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="
                                        props.errors[
                                            `stages.${index}.quorum_type`
                                        ]
                                    "
                                />
                            </div>

                            <div
                                v-if="stage.quorum_type === 'at_least_n'"
                                class="grid gap-2"
                            >
                                <Label
                                    :for="`${controlId}_quorum_count_${index}`"
                                >
                                    {{ QUORUM_COUNT_LABEL }}
                                </Label>
                                <Input
                                    :id="`${controlId}_quorum_count_${index}`"
                                    type="number"
                                    min="2"
                                    :model-value="stage.quorum_count ?? ''"
                                    @update:model-value="
                                        (value: string | number) =>
                                            setWholeNumber(
                                                index,
                                                'quorum_count',
                                                value,
                                            )
                                    "
                                />
                                <InputError
                                    :message="
                                        props.errors[
                                            `stages.${index}.quorum_count`
                                        ]
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`${controlId}_deadline_${index}`">
                                    {{ DEADLINE_LABEL }}
                                </Label>
                                <Input
                                    :id="`${controlId}_deadline_${index}`"
                                    type="number"
                                    min="1"
                                    :model-value="stage.deadline_hours ?? ''"
                                    @update:model-value="
                                        (value: string | number) =>
                                            setWholeNumber(
                                                index,
                                                'deadline_hours',
                                                value,
                                            )
                                    "
                                />
                                <InputError
                                    :message="
                                        props.errors[
                                            `stages.${index}.deadline_hours`
                                        ]
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label
                                    :for="`${controlId}_escalation_${index}`"
                                >
                                    {{ ESCALATION_LABEL }}
                                </Label>
                                <Select
                                    :model-value="
                                        stage.escalation_type ?? NO_SELECTION
                                    "
                                    @update:model-value="
                                        (value) =>
                                            setEscalationType(index, value)
                                    "
                                >
                                    <SelectTrigger
                                        :id="`${controlId}_escalation_${index}`"
                                        class="w-full"
                                    >
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'i18n.components.engine.object_type.approval_definition_card.select_response',
                                                )
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in ESCALATION_OPTIONS"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="
                                        props.errors[
                                            `stages.${index}.escalation_type`
                                        ]
                                    "
                                />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <span class="text-sm leading-none font-medium">
                                {{ CIRCLE_TITLE }}
                            </span>
                            <CandidateCircleEditor
                                :model-value="stage.candidate_sources"
                                :role-options="props.roleOptions"
                                :team-options="props.teamOptions"
                                :user-options="props.userOptions"
                                :field-options="circleFieldOptions"
                                :anchor-mode="isAnchorMode"
                                @update:model-value="
                                    (circle: CandidateCircleValue) =>
                                        updateStage(index, {
                                            candidate_sources: circle,
                                        })
                                "
                            />
                            <InputError
                                :message="
                                    props.errors[
                                        `stages.${index}.candidate_sources`
                                    ]
                                "
                            />
                        </div>

                        <div
                            v-if="stage.escalation_sources !== null"
                            class="grid gap-2"
                        >
                            <span class="text-sm leading-none font-medium">
                                {{ ESCALATION_CIRCLE_TITLE }}
                            </span>
                            <CandidateCircleEditor
                                :model-value="stage.escalation_sources"
                                :role-options="props.roleOptions"
                                :team-options="props.teamOptions"
                                :user-options="props.userOptions"
                                :field-options="circleFieldOptions"
                                :anchor-mode="isAnchorMode"
                                @update:model-value="
                                    (circle: CandidateCircleValue) =>
                                        updateStage(index, {
                                            escalation_sources: circle,
                                        })
                                "
                            />
                            <InputError
                                :message="
                                    props.errors[
                                        `stages.${index}.escalation_sources`
                                    ]
                                "
                            />
                        </div>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="justify-self-start"
                        data-stage-add
                        @click="addStage"
                    >
                        <Plus />
                        {{ STAGE_ADD_LABEL }}
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        {{ STAGES_HINT }}
                    </p>
                    <InputError :message="props.errors.stages" />
                </div>
            </CardContent>
        </Card>

        <Card v-if="props.existing">
            <CardHeader>
                <CardTitle>{{ REMOVAL_TITLE }}</CardTitle>
                <CardDescription>{{ removalDescription }}</CardDescription>
            </CardHeader>
            <CardContent>
                <Button
                    type="button"
                    variant="destructive"
                    data-approval-delete
                    @click="removalOpen = true"
                >
                    {{ REMOVAL_TITLE }}
                </Button>
            </CardContent>
        </Card>

        <ConfirmDialog
            v-if="props.existing"
            :open="removalOpen"
            :title="REMOVAL_TITLE"
            :description="removalConfirmDescription"
            :confirm-label="REMOVAL_TITLE"
            variant="destructive"
            @confirm="removeDefinition"
            @cancel="removalOpen = false"
        />
    </div>
</template>
