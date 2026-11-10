<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CircleAlert, Plus, RotateCcw } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed, ref } from 'vue';
import PromotionRunsController from '@/actions/App/Http/Controllers/Promotion/PromotionRunsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import { statusBadgeColumn } from '@/components/data-grid/StatusBadgeCellRenderer';
import ViewStates from '@/components/engine/ViewStates.vue';
import ReasonedButton from '@/components/ReasonedButton.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';
import { PROMOTION_RUN_STATUS } from '@/lib/statusMaps';
import type { PromotionRunRow } from '@/types/promotion';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        runs?: PromotionRunRow[];
        can_create?: boolean;
        create_reason?: string | null;
    }>(),
    { runs: () => [], can_create: false, create_reason: null },
);

const page = usePage();

const listState = computed<'empty' | 'ready'>(() =>
    props.runs.length === 0 ? 'empty' : 'ready',
);

const rollbackError = computed<string | undefined>(() => {
    const errors: unknown = page.props.errors;

    return typeof errors === 'object' && errors !== null
        ? (errors as Partial<Record<string, string>>).rollback
        : undefined;
});

const pendingRollback = ref<PromotionRunRow | null>(null);
const rollbackPending = ref<boolean>(false);

const rollbackDialogOpen = computed<boolean>({
    get: () => pendingRollback.value !== null,
    set: (value) => {
        if (!value) {
            pendingRollback.value = null;
        }
    },
});

const rollbackDescription = computed<string>(() =>
    pendingRollback.value === null
        ? ''
        : t(
              'i18n.pages.promotion.index.the_configuration_transferred_from_will_be_restored_to_its',
              { value1: pendingRollback.value.counterpart_label },
          ),
);

function confirmRollback(): void {
    const row = pendingRollback.value;

    if (row === null) {
        return;
    }

    rollbackPending.value = true;

    router.post(
        PromotionRunsController.rollback.url({ promotionRun: row.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                rollbackPending.value = false;
                pendingRollback.value = null;
            },
        },
    );
}

function goToCreate(): void {
    router.visit(PromotionRunsController.create.url());
}

function goToReview(row: PromotionRunRow): void {
    router.visit(PromotionRunsController.show.url({ promotionRun: row.id }));
}

const columnDefs: ColDef<PromotionRunRow>[] = [
    {
        colId: 'counterpart_label',
        field: 'counterpart_label',
        headerName: t('i18n.pages.promotion.index.counterpart'),
        flex: 1,
        minWidth: 220,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: PromotionRunRow): string | undefined =>
                row.can_view
                    ? PromotionRunsController.show.url({ promotionRun: row.id })
                    : undefined,
        },
    },
    {
        colId: 'direction_label',
        field: 'direction_label',
        headerName: t('i18n.pages.promotion.index.direction'),
        width: 260,
    },
    statusBadgeColumn<PromotionRunRow>('status', PROMOTION_RUN_STATUS, {
        headerName: t('i18n.pages.promotion.index.status'),
        width: 180,
    }),
    {
        colId: 'artifact_count',
        field: 'artifact_count',
        headerName: t('i18n.pages.promotion.index.artifacts'),
        width: 120,
    },
    {
        colId: 'triggered_by_name',
        field: 'triggered_by_name',
        headerName: t('i18n.pages.promotion.index.triggered_by'),
        width: 200,
        valueFormatter: (params) => params.value ?? '—',
    },
    {
        colId: 'created_at',
        field: 'created_at',
        headerName: t('i18n.pages.promotion.index.created_on'),
        width: 180,
        valueFormatter: (params) => formatDateTime(params.value ?? null),
    },
    actionsColumn<PromotionRunRow>([
        {
            icon: RotateCcw,
            label: t('i18n.pages.promotion.index.revert'),
            variant: 'warning',
            testId: 'promotion-rollback',
            onClick: (row) => (pendingRollback.value = row),
            isDisabled: (row) => !row.can_rollback,
            disabledReason: (row) => row.rollback_reason ?? undefined,
        },
    ]),
];
</script>

<template>
    <ListPage
        :title="t('i18n.pages.promotion.index.promotions')"
        :description="
            t(
                'i18n.pages.promotion.index.transfer_reviewed_configuration_from_the_source_to_live_mode',
            )
        "
    >
        <template #actions>
            <CreateButton
                v-if="can_create"
                :href="PromotionRunsController.create.url()"
                :label="t('i18n.pages.promotion.index.create_promotion')"
            />
            <ReasonedButton
                v-else
                variant="create"
                :reason="create_reason ?? ''"
                data-promotion-create-reason
            >
                <Plus class="size-4" />
                {{ t('i18n.pages.promotion.index.create_promotion') }}
            </ReasonedButton>
        </template>

        <Alert
            v-if="rollbackError !== undefined"
            variant="destructive"
            data-promotion-rollback-error
        >
            <CircleAlert />
            <AlertTitle>{{
                t('i18n.pages.promotion.index.revert_failed')
            }}</AlertTitle>
            <AlertDescription>{{ rollbackError }}</AlertDescription>
        </Alert>

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                skeleton-variant="list"
                :empty-title="t('i18n.pages.promotion.index.no_promotion_yet')"
                :empty-description="
                    t(
                        'i18n.pages.promotion.index.create_a_promotion_to_transfer_changes_to_live_mode',
                    )
                "
                :create-label="t('i18n.pages.promotion.index.create_promotion')"
                :can-create="can_create"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="runs"
                    :is-row-activatable="(row: PromotionRunRow) => row.can_view"
                    :aria-label="t('i18n.pages.promotion.index.promotions')"
                    @row-activate="goToReview"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="rollbackDialogOpen"
            :title="t('i18n.pages.promotion.index.revert_promotion')"
            :description="rollbackDescription"
            :confirm-label="t('i18n.pages.promotion.index.revert')"
            variant="destructive"
            :pending="rollbackPending"
            @confirm="confirmRollback"
        />
    </ListPage>
</template>
