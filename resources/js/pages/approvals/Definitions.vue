<script setup lang="ts">
import type { FormDataType } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AnchorApprovalDefinitionsController from '@/actions/App/Http/Controllers/Approvals/AnchorApprovalDefinitionsController';
import ApprovalDefinitionCard from '@/components/engine/objectType/ApprovalDefinitionCard.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type {
    ApprovalAnchorKey,
    ApprovalDefinitionFormValue,
    ApprovalDefinitionRow,
    ApprovalStageRow,
    ApprovalStageValue,
} from '@/types/approvalDefinitions';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const PAGE_TITLE = t('i18n.pages.approvals.definitions.approval_rules');

const PAGE_DESCRIPTION = t(
    'i18n.pages.approvals.definitions.choose_who_must_approve_a_promotion_before_it_runs',
);

const ANCHOR_KINDS: ApprovalAnchorKey[] = ['promotion'];

interface AnchorDefinitionPayload {
    is_active: boolean;
    stages: ApprovalStageValue[];
}

const props = defineProps<{
    definitions: Record<ApprovalAnchorKey, ApprovalDefinitionRow | null>;
    warnings: Record<ApprovalAnchorKey, string[]>;
    roleOptions: SelectOption[];
    teamOptions: SelectOption[];
    userOptions: SelectOption[];
}>();

const values = ref<Record<ApprovalAnchorKey, ApprovalDefinitionFormValue>>({
    promotion: seed('promotion'),
});

const baselines = ref<Record<ApprovalAnchorKey, string>>({
    promotion: serialize(values.value.promotion),
});

const errors = ref<Record<ApprovalAnchorKey, Record<string, string>>>({
    promotion: {},
});

const processing = ref<boolean>(false);

const backHref = computed<string>(() =>
    AnchorApprovalDefinitionsController.index.url(),
);

const changedKinds = computed<ApprovalAnchorKey[]>(() =>
    ANCHOR_KINDS.filter(
        (kind) => serialize(values.value[kind]) !== baselines.value[kind],
    ),
);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => values.value,
    backHref: () => backHref.value,
});

function serialize(value: ApprovalDefinitionFormValue): string {
    return JSON.stringify(value);
}

function seed(kind: ApprovalAnchorKey): ApprovalDefinitionFormValue {
    const stored = props.definitions[kind];

    if (stored === null) {
        return {
            is_active: false,
            rejection_stage_transition_id: null,
            exclusions: {
                trigger: true,
                last_editor: true,
                creator: true,
                owner: true,
            },
            stages: [],
        };
    }

    return {
        is_active: stored.is_active,
        rejection_stage_transition_id: null,
        exclusions: { ...stored.exclusions },
        stages: stored.stages.map(
            (stage: ApprovalStageRow): ApprovalStageValue => ({
                quorum_type: stage.quorum_type,
                quorum_count: stage.quorum_count,
                deadline_hours: stage.deadline_hours,
                escalation_type: stage.escalation_type,
                escalation_sources: stage.escalation_sources,
                candidate_sources: stage.candidate_sources,
            }),
        ),
    };
}

function onCardUpdate(
    kind: ApprovalAnchorKey,
    value: ApprovalDefinitionFormValue,
): void {
    values.value = { ...values.value, [kind]: value };
}

function onSave(): void {
    const pending = changedKinds.value;

    if (pending.length === 0) {
        return;
    }

    processing.value = true;
    submit(pending, 0);
}

function payloadOf(
    value: ApprovalDefinitionFormValue,
): AnchorDefinitionPayload {
    return { is_active: value.is_active, stages: value.stages };
}

function submit(pending: ApprovalAnchorKey[], index: number): void {
    const kind = pending[index];

    router.put<FormDataType<AnchorDefinitionPayload>>(
        AnchorApprovalDefinitionsController.update.url({ kind }),
        payloadOf(values.value[kind]),
        {
            onError: (received: Record<string, string>): void => {
                errors.value = { ...errors.value, [kind]: received };
                processing.value = false;
            },
            onSuccess: (): void => {
                errors.value = { ...errors.value, [kind]: {} };
                baselines.value = {
                    ...baselines.value,
                    [kind]: serialize(values.value[kind]),
                };

                if (index + 1 < pending.length) {
                    submit(pending, index + 1);

                    return;
                }

                processing.value = false;
                markSaved();
            },
        },
    );
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head :title="PAGE_TITLE" />

        <Heading
            variant="small"
            :title="PAGE_TITLE"
            :description="PAGE_DESCRIPTION"
        />

        <ApprovalDefinitionCard
            v-for="kind in ANCHOR_KINDS"
            :key="kind"
            :model-value="values[kind]"
            :warnings="props.warnings[kind]"
            :role-options="props.roleOptions"
            :team-options="props.teamOptions"
            :user-options="props.userOptions"
            :anchor-kind="kind"
            :existing="props.definitions[kind] !== null"
            :errors="errors[kind]"
            @update:model-value="
                (value: ApprovalDefinitionFormValue) =>
                    onCardUpdate(kind, value)
            "
        />

        <FormActions
            type="button"
            :dirty="isDirty"
            :processing="processing"
            @cancel="requestLeave"
            @save="onSave"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
