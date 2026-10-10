<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import MergeFieldResolution from '@/components/engine/records/MergeFieldResolution.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useRecordMerge } from '@/composables/useRecordMerge';
import { recordRouteKey } from '@/lib/recordRouteKey';
import type {
    MergeCandidates,
    MergeRequestPayload,
    MergeTransferPlan,
} from '@/types/merge';
import {
    MERGE_BLOCK_REASON_LABELS,
    MERGE_TRANSFER_CATEGORY_LABELS,
    MERGE_TRANSFER_POLICY_LABELS,
} from '@/types/merge';
import type { RecordObjectType, RecordPayload } from '@/types/records';

const { t } = useI18n();

const PAGE_TITLE = t('i18n.pages.records.merge.merge_records');

const PAGE_DESCRIPTION = t(
    'i18n.pages.records.merge.two_records_become_one_the_target_remains_the_source',
);

const PARTNER_TITLE = t('i18n.pages.records.merge.select_partner');

const PARTNER_DESCRIPTION = t(
    'i18n.pages.records.merge.select_the_record_to_merge_with_this_one_potential',
);

const PARTNER_PLACEHOLDER = t('i18n.pages.records.merge.select_record');

const DIRECTION_LABEL = t('i18n.pages.records.merge.swap_direction');

const TARGET_BADGE = t('i18n.pages.records.merge.goal');

const TARGET_HINT = t(
    'i18n.pages.records.merge.remains_and_receives_the_values',
);

const SOURCE_BADGE = t('i18n.pages.records.merge.source');

const SOURCE_HINT = t(
    'i18n.pages.records.merge.is_merged_and_then_moved_to_the_trash',
);

const RESOLUTION_TITLE = t('i18n.pages.records.merge.resolve_values');

const RESOLUTION_DESCRIPTION = t(
    'i18n.pages.records.merge.click_a_value_to_use_it_without_a_selection',
);

const SUMMARY_TITLE = t('i18n.pages.records.merge.preview');

const SUMMARY_DESCRIPTION = t(
    'i18n.pages.records.merge.these_items_move_when_you_confirm_items_that_stay',
);

const BLOCKED_TITLE = t('i18n.pages.records.merge.merge_not_possible');

const REASON_LABEL = t('i18n.pages.records.merge.reason');

const TRUNCATED_HINT = t(
    'i18n.pages.records.merge.only_recently_changed_records_are_offered',
);

const props = defineProps<{
    objectType: RecordObjectType;
    record: RecordPayload;
    candidates: MergeCandidates;
}>();

usePageBreadcrumbs(() => [
    {
        title: props.record.title,
        href: RecordsController.edit.url({
            record: recordRouteKey(props.record),
        }),
    },
    { title: PAGE_TITLE },
]);

const partnerId = ref<string>('');
const recordIsTarget = ref<boolean>(false);
const overrides = ref<Record<string, 'target' | 'source'>>({});
const reason = ref<string>('');

const { plan, previewing, merging, preview, merge } = useRecordMerge(
    (result) => {
        router.visit(RecordsController.edit.url({ record: result.target_id }));
    },
);

const sortedOptions = computed(() => {
    const suggested = new Set(props.candidates.suggested_ids);

    return [...props.candidates.options].sort((left, right) => {
        const leftSuggested = suggested.has(left.value) ? 0 : 1;
        const rightSuggested = suggested.has(right.value) ? 0 : 1;

        return leftSuggested - rightSuggested;
    });
});

const hasPartner = computed<boolean>(() => partnerId.value !== '');

const targetId = computed<string>(() =>
    recordIsTarget.value ? props.record.id : partnerId.value,
);

const sourceId = computed<string>(() =>
    recordIsTarget.value ? partnerId.value : props.record.id,
);

const partnerLabel = computed<string>(
    () =>
        props.candidates.options.find(
            (option) => option.value === partnerId.value,
        )?.label ?? partnerId.value,
);

const targetLabel = computed<string>(() =>
    recordIsTarget.value ? props.record.title : partnerLabel.value,
);

const sourceLabel = computed<string>(() =>
    recordIsTarget.value ? partnerLabel.value : props.record.title,
);

const blockers = computed(() => plan.value?.blockers ?? []);

const requiresReason = computed<boolean>(
    () => plan.value?.requires_reason === true,
);

const canMerge = computed<boolean>(
    () =>
        hasPartner.value &&
        plan.value !== null &&
        plan.value.is_mergeable &&
        !merging.value,
);

function payload(): MergeRequestPayload {
    return {
        targetId: targetId.value,
        sourceId: sourceId.value,
        reason: reason.value.trim() === '' ? null : reason.value.trim(),
        overrides: overrides.value,
    };
}

function refreshPlan(): void {
    if (!hasPartner.value) {
        plan.value = null;

        return;
    }

    void preview(props.record.id, payload());
}

watch([partnerId, recordIsTarget], () => {
    overrides.value = {};
    refreshPlan();
});

watch(reason, () => {
    if (requiresReason.value) {
        refreshPlan();
    }
});

function onChoose(key: string, origin: 'target' | 'source'): void {
    overrides.value = { ...overrides.value, [key]: origin };
    refreshPlan();
}

function onReset(key: string): void {
    const next = { ...overrides.value };

    delete next[key];
    overrides.value = next;
    refreshPlan();
}

function onSwapDirection(): void {
    recordIsTarget.value = !recordIsTarget.value;
}

function onSubmit(): void {
    if (!canMerge.value) {
        return;
    }

    void merge(props.record.id, payload());
}

function onCancel(): void {
    router.visit(
        RecordsController.edit.url({ record: recordRouteKey(props.record) }),
    );
}

function transferLabel(transfer: MergeTransferPlan): string {
    return MERGE_TRANSFER_CATEGORY_LABELS[transfer.category];
}

function policyLabel(transfer: MergeTransferPlan): string {
    return MERGE_TRANSFER_POLICY_LABELS[transfer.policy];
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

        <Card>
            <CardHeader>
                <CardTitle>{{ PARTNER_TITLE }}</CardTitle>
                <CardDescription>{{ PARTNER_DESCRIPTION }}</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="merge-partner">{{
                        t('i18n.pages.records.merge.second_record')
                    }}</Label>
                    <Combobox
                        id="merge-partner"
                        v-model="partnerId"
                        :options="sortedOptions"
                        :searchable="true"
                        :placeholder="PARTNER_PLACEHOLDER"
                    />
                    <p
                        v-if="props.candidates.is_truncated"
                        class="text-xs text-muted-foreground"
                        data-merge-truncated
                    >
                        {{ TRUNCATED_HINT }}
                    </p>
                </div>

                <div
                    v-if="hasPartner"
                    class="flex flex-col gap-3"
                    data-merge-direction
                >
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div
                            class="flex flex-col gap-1 rounded-md border border-input p-3"
                            data-merge-target-summary
                        >
                            <Badge variant="success" class="w-fit">
                                {{ TARGET_BADGE }}
                            </Badge>
                            <span class="text-sm font-medium">
                                {{ targetLabel }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ TARGET_HINT }}
                            </span>
                        </div>
                        <div
                            class="flex flex-col gap-1 rounded-md border border-input p-3"
                            data-merge-source-summary
                        >
                            <Badge variant="destructive" class="w-fit">
                                {{ SOURCE_BADGE }}
                            </Badge>
                            <span class="text-sm font-medium">
                                {{ sourceLabel }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ SOURCE_HINT }}
                            </span>
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        data-merge-swap
                        @click="onSwapDirection"
                    >
                        {{ DIRECTION_LABEL }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <Alert
            v-if="blockers.length > 0"
            variant="destructive"
            data-merge-blockers
        >
            <AlertTitle>{{ BLOCKED_TITLE }}</AlertTitle>
            <AlertDescription>
                <ul class="list-disc pl-4">
                    <li
                        v-for="blocker in blockers"
                        :key="`${blocker.reason}-${blocker.detail ?? ''}`"
                    >
                        {{
                            blocker.detail !== null &&
                            blocker.reason === 'rule_forbids'
                                ? blocker.detail
                                : MERGE_BLOCK_REASON_LABELS[blocker.reason]
                        }}
                    </li>
                </ul>
            </AlertDescription>
        </Alert>

        <Card v-if="plan !== null">
            <CardHeader>
                <CardTitle>{{ RESOLUTION_TITLE }}</CardTitle>
                <CardDescription>
                    {{ RESOLUTION_DESCRIPTION }}
                    <template v-if="plan.rule_name !== null">
                        {{ t('i18n.pages.records.merge.the_applied_rule_is')
                        }}{{ plan.rule_name }}“.
                    </template>
                </CardDescription>
            </CardHeader>
            <CardContent>
                <MergeFieldResolution
                    :fields="plan.fields"
                    :overrides="overrides"
                    :disabled="previewing"
                    @choose="onChoose"
                    @reset="onReset"
                />
            </CardContent>
        </Card>

        <Card v-if="plan !== null">
            <CardHeader>
                <CardTitle>{{ SUMMARY_TITLE }}</CardTitle>
                <CardDescription>{{ SUMMARY_DESCRIPTION }}</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <ul class="grid gap-1 sm:grid-cols-2" data-merge-transfers>
                    <li
                        v-for="transfer in plan.transfers"
                        :key="transfer.category"
                        class="flex items-center justify-between gap-3 text-sm"
                        :data-merge-transfer="transfer.category"
                    >
                        <span>{{ transferLabel(transfer) }}</span>
                        <span class="text-muted-foreground">
                            {{ transfer.count }} · {{ policyLabel(transfer) }}
                        </span>
                    </li>
                </ul>

                <div v-if="requiresReason" class="grid gap-2">
                    <Label for="merge-reason">{{ REASON_LABEL }}</Label>
                    <Textarea id="merge-reason" v-model="reason" rows="3" />
                    <InputError
                        :message="
                            reason.trim() === ''
                                ? MERGE_BLOCK_REASON_LABELS.reason_required
                                : undefined
                        "
                    />
                </div>
            </CardContent>
        </Card>

        <FormActions
            type="button"
            :dirty="canMerge"
            :processing="merging || previewing"
            @cancel="onCancel"
            @save="onSubmit"
        />
    </div>
</template>
