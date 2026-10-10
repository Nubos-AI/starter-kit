<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { store } from '@/actions/App/Http/Controllers/Engine/BulkActionsController';
import BulkConfirmDialog from '@/components/engine/BulkConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Separator } from '@/components/ui/separator';
import { useBatchProgress } from '@/composables/useBatchProgress';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import type {
    BulkAction,
    SelectionPayload,
    SelectionSummary,
} from '@/composables/useRecordSelection';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

const { t } = useI18n();

const REQUEST_TIMEOUT_MS = 15000;

const DISPATCH_ERROR_MESSAGE = t(
    'i18n.components.engine.bulk_action_bar.the_bulk_action_could_not_be_started_please_try',
);

const props = defineProps<{
    objectType: RecordObjectType;
    summary: SelectionSummary;
    fieldDefinitions: FieldDefinition[];
    buildSelectionPayload: () => SelectionPayload;
}>();

const { canForObjectType } = usePermissions();

const emit = defineEmits<{
    finished: [];
    clear: [];
    selectAllMatching: [];
}>();

const pendingAction = ref<BulkAction | null>(null);
const dispatching = ref<boolean>(false);

const {
    start: startBatch,
    status: batchStatus,
    progress: batchProgress,
    processedJobs,
    totalJobs,
    failedJobs,
    partialErrors,
    active: batchActive,
} = useBatchProgress({ onSettled: () => emit('finished') });

const confirmOpen = computed<boolean>(() => pendingAction.value !== null);

const showProgress = computed<boolean>(
    () => batchActive.value || batchStatus.value === 'finished',
);

const hasPartialErrors = computed<boolean>(
    () => partialErrors.value.length > 0,
);

const errorReportOpen = ref<boolean>(false);

const openAction = (action: BulkAction): void => {
    pendingAction.value = action;
};

const onDialogOpenChange = (value: boolean): void => {
    if (!value) {
        pendingAction.value = null;
    }
};

const dispatch = async (
    action: BulkAction,
    payload?: Record<string, unknown>,
): Promise<void> => {
    if (dispatching.value) {
        return;
    }

    dispatching.value = true;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
        const body = {
            action,
            selection: props.buildSelectionPayload(),
            ...(payload !== undefined ? { payload } : {}),
        };

        const response = await fetch(
            store.url({ objectType: props.objectType }),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: true }),
                body: JSON.stringify(body),
                signal: controller.signal,
            },
        );

        if (response.status !== 202) {
            toast.error(
                await readErrorReason(response, DISPATCH_ERROR_MESSAGE),
            );

            return;
        }

        const data = (await response.json()) as { batchId: string };
        startBatch(data.batchId, action);
    } catch {
        toast.error(DISPATCH_ERROR_MESSAGE);
    } finally {
        clearTimeout(timeout);
        dispatching.value = false;
    }
};

watch(batchActive, (active) => {
    if (active) {
        errorReportOpen.value = false;
    }
});

const onConfirm = (payload?: Record<string, unknown>): void => {
    const action = pendingAction.value;
    pendingAction.value = null;

    if (action === null) {
        return;
    }

    void dispatch(action, payload);
};
</script>

<template>
    <div
        class="relative flex shrink-0 flex-wrap items-center gap-2 border-b bg-muted/40 px-4 py-2"
        role="region"
        :aria-label="t('i18n.components.engine.bulk_action_bar.bulk_actions')"
    >
        <Badge variant="secondary">{{ summary.label }}</Badge>

        <Button
            v-if="summary.mode === 'visible'"
            variant="link"
            size="sm"
            @click="emit('selectAllMatching')"
        >
            {{ t('i18n.components.engine.bulk_action_bar.select_all_matches') }}
        </Button>

        <Separator orientation="vertical" class="h-5" />

        <Button
            v-if="canForObjectType(props.objectType.slug, 'update')"
            variant="outline"
            size="sm"
            :disabled="batchActive"
            @click="openAction('set-field')"
        >
            {{ t('i18n.components.engine.bulk_action_bar.set_field') }}
        </Button>
        <Button
            v-if="canForObjectType(props.objectType.slug, 'update')"
            variant="outline"
            size="sm"
            :disabled="batchActive"
            @click="openAction('restore')"
        >
            {{ t('i18n.components.engine.bulk_action_bar.restore') }}
        </Button>
        <Button
            v-if="canForObjectType(props.objectType.slug, 'export')"
            variant="outline"
            size="sm"
            :disabled="batchActive"
            @click="openAction('export-csv')"
        >
            {{ t('i18n.components.engine.bulk_action_bar.csv_export') }}
        </Button>
        <Button
            v-if="canForObjectType(props.objectType.slug, 'delete')"
            variant="destructive"
            size="sm"
            :disabled="batchActive"
            @click="openAction('soft-delete')"
        >
            <Trash2 />
            {{ t('i18n.components.engine.bulk_action_bar.delete') }}
        </Button>

        <div class="ml-auto flex items-center gap-3">
            <div v-if="showProgress" class="flex items-center gap-2">
                <div
                    role="progressbar"
                    :aria-valuenow="batchProgress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-label="
                        t(
                            'i18n.components.engine.bulk_action_bar.bulk_action_progress',
                        )
                    "
                    class="h-2 w-40 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full bg-primary transition-all"
                        :style="{ width: `${batchProgress}%` }"
                    />
                </div>
                <span class="text-xs text-muted-foreground">
                    {{ processedJobs }}/{{ totalJobs }}
                    {{ t('i18n.components.engine.bulk_action_bar.tasks') }}
                </span>
            </div>

            <Collapsible v-if="hasPartialErrors" v-model:open="errorReportOpen">
                <CollapsibleTrigger as-child>
                    <Button variant="ghost" size="sm">
                        {{
                            errorReportOpen
                                ? t(
                                      'i18n.components.engine.bulk_action_bar.hide_error_report',
                                  )
                                : t(
                                      'i18n.components.engine.bulk_action_bar.show_error_report',
                                  )
                        }}
                        ({{ failedJobs }})
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent
                    class="absolute top-full right-4 z-10 mt-1 w-80 rounded-md border bg-popover p-2 shadow-md"
                >
                    <ul
                        role="list"
                        :aria-label="
                            t(
                                'i18n.components.engine.bulk_action_bar.bulk_action_partial_failure_report',
                            )
                        "
                        class="max-h-48 space-y-1 overflow-y-auto text-xs"
                    >
                        <li
                            v-for="error in partialErrors"
                            :key="error.recordId"
                            class="rounded border-l-2 border-destructive bg-destructive/5 px-2 py-1"
                        >
                            <span class="font-medium text-destructive">{{
                                error.recordId
                            }}</span>
                            <span class="block text-muted-foreground">{{
                                error.message
                            }}</span>
                        </li>
                    </ul>
                </CollapsibleContent>
            </Collapsible>

            <Button variant="ghost" size="sm" @click="emit('clear')">
                {{
                    t('i18n.components.engine.bulk_action_bar.clear_selection')
                }}
            </Button>
        </div>

        <BulkConfirmDialog
            :open="confirmOpen"
            :action="pendingAction"
            :count-label="summary.label"
            :field-definitions="fieldDefinitions"
            :requires-deletion-reason="props.objectType.requiresDeletionReason"
            @update:open="onDialogOpenChange"
            @confirm="onConfirm"
        />
    </div>
</template>
