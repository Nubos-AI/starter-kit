<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalsController from '@/actions/App/Http/Controllers/Approvals/ApprovalsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
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
import { Combobox } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime } from '@/lib/formatDate';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

interface ApprovalHistoryEntry {
    id: string;
    type: string;
    type_label: string;
    actor_label: string;
    on_behalf_of_label: string | null;
    reason: string | null;
    occurred_at: string;
}

interface ApprovalDetail {
    id: string;
    record_id: string | null;
    anchor_label: string;
    anchor_kind_label: string;
    anchor_url: string | null;
    transition_label: string;
    stage_label: string;
    deadline_at: string | null;
    is_overdue: boolean;
    on_behalf_of_id: string | null;
    on_behalf_of_label: string | null;
    history: ApprovalHistoryEntry[];
    can_decide: boolean;
    can_cancel: boolean;
    decision_reason: string | null;
    on_behalf_of_options: SelectOption[];
}

type Decision = 'approved' | 'rejected' | '';

const PAGE_TITLE = t('i18n.pages.approvals.decide.decide_approval');

const PAGE_DESCRIPTION = t(
    'i18n.pages.approvals.decide.review_the_process_and_its_history_then_record_your',
);

const ANCHOR_TITLE = t('i18n.pages.approvals.decide.process');

const ANCHOR_DESCRIPTION = t(
    'i18n.pages.approvals.decide.what_you_are_deciding_on',
);

const HISTORY_TITLE = t('i18n.pages.approvals.decide.history');

const HISTORY_DESCRIPTION = t(
    'i18n.pages.approvals.decide.all_previous_events_in_this_process',
);

const HISTORY_EMPTY = t(
    'i18n.pages.approvals.decide.nothing_has_been_recorded_for_this_process_yet',
);

const DECISION_TITLE = t('i18n.pages.approvals.decide.your_decision');

const DECISION_DESCRIPTION = t(
    'i18n.pages.approvals.decide.a_rejection_requires_a_reason_which_will_be_recorded',
);

const BLOCKED_TITLE = t('i18n.pages.approvals.decide.you_cannot_decide_here');

const FAILED_TITLE = t(
    'i18n.pages.approvals.decide.the_decision_was_not_applied',
);

const DECISION_MISSING = t(
    'i18n.pages.approvals.decide.please_select_a_decision',
);

const CANCEL_TITLE = t('i18n.pages.approvals.decide.cancel_process');

const CANCEL_DESCRIPTION = t(
    'i18n.pages.approvals.decide.a_cancelled_process_can_no_longer_be_decided_and',
);

const CANCEL_CONFIRM_DESCRIPTION = t(
    'i18n.pages.approvals.decide.are_you_sure_you_want_to_cancel_this_approval',
);

const CANCEL_REASON_LABEL = t(
    'i18n.pages.approvals.decide.reason_for_cancellation_optional',
);

const REASON_MISSING = t(
    'i18n.pages.approvals.decide.please_provide_a_reason_for_the_rejection',
);

const DECISION_OPTIONS: SelectOption[] = [
    { value: 'approved', label: t('i18n.pages.approvals.decide.approve') },
    { value: 'rejected', label: t('i18n.pages.approvals.decide.reject') },
];

const props = defineProps<{
    approval: ApprovalDetail;
}>();

usePageBreadcrumbs(() => [{ title: props.approval.anchor_label }]);

const decision = ref<Decision>('');
const reason = ref<string>('');
const onBehalfOfId = ref<string | null>(props.approval.on_behalf_of_id);
const processing = ref<boolean>(false);
const errors = ref<Record<string, string>>({});
const cancelOpen = ref<boolean>(false);
const cancelReason = ref<string>('');
const cancelling = ref<boolean>(false);

const requiresReason = computed<boolean>(() => decision.value === 'rejected');

const hasDelegation = computed<boolean>(
    () => props.approval.on_behalf_of_options.length > 0,
);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        decision: decision.value,
        reason: reason.value,
        onBehalfOfId: onBehalfOfId.value,
    }),
    backHref: ApprovalsController.index.url(),
});

const deadlineLabel = computed<string>(() =>
    formatDateTime(props.approval.deadline_at),
);

function historyLine(entry: ApprovalHistoryEntry): string {
    const actor =
        entry.on_behalf_of_label === null
            ? entry.actor_label
            : t('i18n.pages.approvals.decide.acting_for', {
                  value1: entry.actor_label,
                  value2: entry.on_behalf_of_label,
              });

    return `${entry.type_label} · ${actor}`;
}

function onCancelProcess(): void {
    if (cancelling.value) {
        return;
    }

    cancelOpen.value = false;
    cancelling.value = true;

    const trimmedReason = cancelReason.value.trim();

    router.post(
        ApprovalsController.cancel.url({ approval: props.approval.id }),
        { reason: trimmedReason === '' ? null : trimmedReason },
        {
            preserveScroll: true,
            onSuccess: () => {
                markSaved();
            },
            onFinish: () => {
                cancelling.value = false;
            },
        },
    );
}

function onSubmit(): void {
    if (!props.approval.can_decide || processing.value) {
        return;
    }

    if (decision.value === '') {
        errors.value = { decision: DECISION_MISSING };

        return;
    }

    const trimmedReason = reason.value.trim();

    if (requiresReason.value && trimmedReason === '') {
        errors.value = { reason: REASON_MISSING };

        return;
    }

    errors.value = {};
    processing.value = true;

    router.post(
        ApprovalsController.decide.url({ approval: props.approval.id }),
        {
            decision: decision.value,
            reason: trimmedReason === '' ? null : trimmedReason,
            onBehalfOfId: hasDelegation.value ? onBehalfOfId.value : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                markSaved();
            },
            onError: (bag: Record<string, string>) => {
                errors.value = bag;
            },
            onFinish: () => {
                processing.value = false;
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

        <Card>
            <CardHeader>
                <CardTitle>{{ ANCHOR_TITLE }}</CardTitle>
                <CardDescription>{{ ANCHOR_DESCRIPTION }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <span class="text-xs text-muted-foreground">{{
                        t('i18n.pages.approvals.decide.process')
                    }}</span>
                    <Link
                        v-if="props.approval.anchor_url !== null"
                        class="text-sm text-primary underline-offset-4 hover:underline"
                        :href="props.approval.anchor_url"
                        data-approval-anchor
                    >
                        {{ props.approval.anchor_label }}
                    </Link>
                    <span v-else class="text-sm" data-approval-anchor>
                        {{ props.approval.anchor_label }}
                    </span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-xs text-muted-foreground">{{
                        t('i18n.pages.approvals.decide.kind')
                    }}</span>
                    <span class="text-sm" data-approval-anchor-kind>
                        {{ props.approval.anchor_kind_label }}
                    </span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-xs text-muted-foreground">{{
                        t('i18n.pages.approvals.decide.transition')
                    }}</span>
                    <span class="text-sm">
                        {{ props.approval.transition_label }}
                    </span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-xs text-muted-foreground">{{
                        t('i18n.pages.approvals.decide.level')
                    }}</span>
                    <span class="text-sm">{{
                        props.approval.stage_label
                    }}</span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-xs text-muted-foreground">{{
                        t('i18n.pages.approvals.decide.deadline')
                    }}</span>
                    <span
                        class="text-sm"
                        :class="
                            props.approval.is_overdue ? 'text-destructive' : ''
                        "
                    >
                        {{ deadlineLabel }}
                    </span>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ HISTORY_TITLE }}</CardTitle>
                <CardDescription>{{ HISTORY_DESCRIPTION }}</CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="props.approval.history.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ HISTORY_EMPTY }}
                </p>
                <ul v-else class="flex flex-col gap-3" data-approval-history>
                    <li
                        v-for="entry in props.approval.history"
                        :key="entry.id"
                        class="flex flex-col gap-0.5"
                    >
                        <span class="text-sm">{{ historyLine(entry) }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ formatDateTime(entry.occurred_at) }}
                        </span>
                        <span
                            v-if="entry.reason !== null"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('i18n.pages.approvals.decide.reason') }}
                            {{ entry.reason }}
                        </span>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ DECISION_TITLE }}</CardTitle>
                <CardDescription>{{ DECISION_DESCRIPTION }}</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <Alert v-if="!props.approval.can_decide" data-approval-blocked>
                    <AlertTitle>{{ BLOCKED_TITLE }}</AlertTitle>
                    <AlertDescription>
                        {{ props.approval.decision_reason }}
                    </AlertDescription>
                </Alert>

                <Alert
                    v-if="errors.decision !== undefined"
                    variant="destructive"
                    data-approval-decision-error
                >
                    <AlertTitle>{{ FAILED_TITLE }}</AlertTitle>
                    <AlertDescription>{{ errors.decision }}</AlertDescription>
                </Alert>

                <fieldset
                    :disabled="!props.approval.can_decide"
                    class="flex flex-col gap-4"
                >
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="approval-decision">{{
                            t('i18n.pages.approvals.decide.decision')
                        }}</Label>
                        <Select v-model="decision">
                            <SelectTrigger
                                id="approval-decision"
                                class="w-full"
                            >
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.approvals.decide.please_select',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in DECISION_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-if="hasDelegation" class="grid gap-2 sm:max-w-sm">
                        <Label for="approval-on-behalf-of">
                            {{
                                t(
                                    'i18n.pages.approvals.decide.decide_on_behalf_of',
                                )
                            }}
                        </Label>
                        <Combobox
                            id="approval-on-behalf-of"
                            v-model="onBehalfOfId"
                            :options="props.approval.on_behalf_of_options"
                            :searchable="
                                props.approval.on_behalf_of_options.length > 8
                            "
                            :placeholder="
                                t('i18n.pages.approvals.decide.select_person')
                            "
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="approval-reason">
                            {{ t('i18n.pages.approvals.decide.reason_2')
                            }}{{
                                requiresReason
                                    ? ''
                                    : t('i18n.pages.approvals.decide.optional')
                            }}
                        </Label>
                        <Textarea
                            id="approval-reason"
                            v-model="reason"
                            rows="3"
                        />
                        <InputError :message="errors.reason" />
                    </div>
                </fieldset>
            </CardContent>
        </Card>

        <Card v-if="props.approval.can_cancel">
            <CardHeader>
                <CardTitle>{{ CANCEL_TITLE }}</CardTitle>
                <CardDescription>{{ CANCEL_DESCRIPTION }}</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="grid gap-2">
                    <Label for="approval-cancel-reason">
                        {{ CANCEL_REASON_LABEL }}
                    </Label>
                    <Textarea
                        id="approval-cancel-reason"
                        v-model="cancelReason"
                        rows="2"
                    />
                </div>
                <Button
                    type="button"
                    variant="destructive"
                    class="self-start justify-self-start"
                    :disabled="cancelling"
                    data-approval-cancel
                    @click="cancelOpen = true"
                >
                    {{ CANCEL_TITLE }}
                </Button>
            </CardContent>
        </Card>

        <FormActions
            type="button"
            :dirty="isDirty"
            :processing="processing"
            @cancel="requestLeave"
            @save="onSubmit"
        />

        <ConfirmDialog
            v-if="props.approval.can_cancel"
            :open="cancelOpen"
            :title="CANCEL_TITLE"
            :description="CANCEL_CONFIRM_DESCRIPTION"
            :confirm-label="CANCEL_TITLE"
            :pending="cancelling"
            variant="destructive"
            @confirm="onCancelProcess"
            @cancel="cancelOpen = false"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
