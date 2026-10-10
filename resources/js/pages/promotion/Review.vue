<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { CircleAlert, Info } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PromotionRunsController from '@/actions/App/Http/Controllers/Promotion/PromotionRunsController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PromotionDiffCard from '@/components/promotion/PromotionDiffCard.vue';
import type { PromotionReviewState } from '@/components/promotion/promotionDiffColumns';
import PromotionReportCard from '@/components/promotion/PromotionReportCard.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type {
    ConflictResolutionValue,
    PromotionDiffGroup,
    PromotionDiffRow,
    PromotionReport,
    PromotionReviewRun,
} from '@/types/promotion';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = defineProps<{
    run: PromotionReviewRun;
    groups: PromotionDiffGroup[];
    decision_options: SelectOption[];
    source_error: string | null;
    report: PromotionReport | null;
}>();

const page = usePage();

usePageBreadcrumbs(() => [
    { title: t('i18n.pages.promotion.review.review_promotion') },
]);

const errors = computed<Partial<Record<string, string>>>(() => {
    const bag: unknown = page.props.errors;

    return typeof bag === 'object' && bag !== null
        ? (bag as Partial<Record<string, string>>)
        : {};
});

const rows = computed<PromotionDiffRow[]>(() =>
    props.groups.flatMap((group) => group.rows),
);

const rowKey = (row: PromotionDiffRow): string => `${row.kind}|${row.key}`;

const selectedKeys = ref<string[]>([]);
const overwriteKeys = ref<string[]>([]);
const decisions = ref<Partial<Record<string, ConflictResolutionValue>>>({});
const processing = ref<boolean>(false);

function adoptServerState(): void {
    selectedKeys.value = rows.value
        .filter((row) => row.is_selected && !row.is_pulled_in)
        .map(rowKey);
    overwriteKeys.value = rows.value.filter((row) => row.overwrite).map(rowKey);
    decisions.value = Object.fromEntries(
        rows.value.flatMap((row) =>
            row.decision === null ? [] : [[rowKey(row), row.decision]],
        ),
    );
}

adoptServerState();

watch(() => props.groups, adoptServerState, { flush: 'sync' });

const canEdit = computed<boolean>(
    () => props.run.can_update && props.source_error === null,
);

const isHandSelected = (row: PromotionDiffRow): boolean =>
    selectedKeys.value.includes(rowKey(row));

const isChecked = (row: PromotionDiffRow): boolean =>
    isHandSelected(row) || row.is_pulled_in;

const isOverwritten = (row: PromotionDiffRow): boolean =>
    overwriteKeys.value.includes(rowKey(row));

function selectionPayload() {
    return rows.value.filter(isHandSelected).map((row) => ({
        artifactKind: row.kind,
        artifactKey: row.key,
        overwrite: isOverwritten(row),
    }));
}

function decisionsPayload(checkedOnly: boolean) {
    return rows.value.flatMap((row) => {
        const decision = decisions.value[rowKey(row)];

        return row.state === 'conflicted' &&
            decision !== undefined &&
            (!checkedOnly || isChecked(row))
            ? [
                  {
                      artifactKind: row.kind,
                      artifactKey: row.key,
                      decision,
                  },
              ]
            : [];
    });
}

const reviewState: PromotionReviewState = {
    isChecked,
    setChecked: (row, checked) => {
        const key = rowKey(row);
        const others = selectedKeys.value.filter((entry) => entry !== key);

        selectedKeys.value = checked ? [...others, key] : others;

        if (!checked) {
            overwriteKeys.value = overwriteKeys.value.filter(
                (entry) => entry !== key,
            );
        }
    },
    isOverwritten,
    setOverwritten: (row, overwritten) => {
        const key = rowKey(row);
        const others = overwriteKeys.value.filter((entry) => entry !== key);

        overwriteKeys.value = overwritten ? [...others, key] : others;

        if (overwritten && !selectedKeys.value.includes(key)) {
            selectedKeys.value = [...selectedKeys.value, key];
        }
    },
    decisionOf: (row) => decisions.value[rowKey(row)],
    decide: (row, decision) => {
        decisions.value = { ...decisions.value, [rowKey(row)]: decision };
    },
    decisionOptions: () => props.decision_options,
    canEdit: () => canEdit.value,
};

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        selection: selectionPayload(),
        conflictDecisions: decisionsPayload(true),
    }),
    backHref: PromotionRunsController.index.url(),
});

const undecidedCount = computed<number>(
    () =>
        rows.value.filter(
            (row) =>
                row.state === 'conflicted' &&
                isChecked(row) &&
                decisions.value[rowKey(row)] === undefined,
        ).length,
);

const saveEnabled = computed<boolean>(
    () => canEdit.value && (isDirty.value || props.run.has_outdated_decisions),
);

const submitReason = computed<string | null>(() => {
    if (isDirty.value) {
        return t(
            'i18n.pages.promotion.review.please_save_your_selection_first',
        );
    }

    if (!props.run.can_submit) {
        return (
            props.run.submit_reason ??
            t(
                'i18n.pages.promotion.review.the_promotion_cannot_be_submitted_right_now',
            )
        );
    }

    if (undecidedCount.value > 0) {
        return t(
            'i18n.pages.promotion.review.resolve_all_conflicts_in_the_selection_first',
        );
    }

    return null;
});

const headingDescription = computed<string>(() =>
    t(
        'i18n.pages.promotion.review.comparison_with_select_the_artifacts_to_transfer_to_live',
        { value1: props.run.counterpart_label },
    ),
);

const updateUrl = (): string =>
    PromotionRunsController.update.url({ promotionRun: props.run.id });

const visitCallbacks = {
    preserveScroll: true,
    onStart: () => {
        processing.value = true;
    },
    onFinish: () => {
        processing.value = false;
    },
};

function save(): void {
    router.put(
        updateUrl(),
        {
            mode: 'selected',
            selection: selectionPayload(),
            conflictDecisions: decisionsPayload(true),
        },
        { ...visitCallbacks, onSuccess: () => markSaved() },
    );
}

function takeAll(): void {
    router.put(
        updateUrl(),
        { mode: 'all', conflictDecisions: decisionsPayload(false) },
        { ...visitCallbacks, onSuccess: () => markSaved() },
    );
}

function submit(): void {
    router.post(
        PromotionRunsController.submit.url({ promotionRun: props.run.id }),
        {},
        visitCallbacks,
    );
}
</script>

<template>
    <Head :title="t('i18n.pages.promotion.review.review_promotion')" />

    <div class="flex flex-col gap-6 p-3">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.promotion.review.review_promotion')"
                :description="headingDescription"
            />
            <Button
                variant="outline"
                data-promotion-take-all
                :disabled="!canEdit || processing"
                @click="takeAll"
            >
                {{ t('i18n.pages.promotion.review.apply_all') }}
            </Button>
        </div>

        <Alert v-if="!run.can_update" data-promotion-readonly>
            <Info />
            <AlertTitle>{{
                t('i18n.pages.promotion.review.view_only')
            }}</AlertTitle>
            <AlertDescription>
                {{ run.update_reason }}
                {{
                    t(
                        'i18n.pages.promotion.review.the_comparison_shows_the_current_state_of_both_sides',
                    )
                }}
            </AlertDescription>
        </Alert>

        <PromotionReportCard v-if="report !== null" :report="report" />

        <Alert
            v-if="source_error !== null"
            variant="destructive"
            data-promotion-source-error
        >
            <CircleAlert />
            <AlertTitle>{{
                t('i18n.pages.promotion.review.source_unavailable')
            }}</AlertTitle>
            <AlertDescription>{{ source_error }}</AlertDescription>
        </Alert>

        <p
            v-if="groups.length === 0 && source_error === null"
            class="text-sm text-muted-foreground"
        >
            {{
                t(
                    'i18n.pages.promotion.review.there_are_no_differences_between_the_two_sides',
                )
            }}
        </p>

        <PromotionDiffCard
            v-for="group in groups"
            :key="group.kind"
            :group="group"
            :state="reviewState"
        />

        <div class="flex flex-col gap-2">
            <InputError :message="errors.selection" />
            <InputError :message="errors.conflictDecisions" />
            <InputError :message="errors.source" />
            <InputError :message="errors.mode" />

            <FormActions
                type="button"
                :dirty="saveEnabled"
                :processing="processing"
                @save="save"
                @cancel="requestLeave"
            />
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.promotion.review.approval')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.promotion.review.if_approval_is_enabled_under_approval_rules_approvers_decide',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p
                    v-if="submitReason !== null"
                    id="promotion-submit-reason"
                    class="text-sm text-muted-foreground"
                    data-promotion-submit-reason
                >
                    {{ submitReason }}
                </p>
                <InputError :message="errors.conflicts" />
                <InputError :message="errors.status" />
                <div class="flex justify-end">
                    <Button
                        data-promotion-submit
                        :disabled="processing || submitReason !== null"
                        :aria-describedby="
                            submitReason !== null
                                ? 'promotion-submit-reason'
                                : undefined
                        "
                        @click="submit"
                    >
                        {{
                            t('i18n.pages.promotion.review.submit_for_approval')
                        }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
