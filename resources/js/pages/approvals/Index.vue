<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import type {
    ColDef,
    ICellRendererParams,
    ValueGetterParams,
} from 'ag-grid-community';
import type { PropType } from 'vue';
import { computed, defineComponent, h } from 'vue';
import ApprovalsController from '@/actions/App/Http/Controllers/Approvals/ApprovalsController';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import ViewStates from '@/components/engine/ViewStates.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';

const { t } = useI18n();

interface ApprovalWorkloadRow {
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
}

const PAGE_TITLE = t('i18n.pages.approvals.index.my_approvals');

const PAGE_DESCRIPTION = t(
    'i18n.pages.approvals.index.all_processes_you_may_currently_decide_on_your_own',
);

const EMPTY_TITLE = t('i18n.pages.approvals.index.nothing_to_decide');

const EMPTY_DESCRIPTION = t(
    'i18n.pages.approvals.index.no_approval_processes_are_currently_awaiting_your_decision',
);

const props = withDefaults(
    defineProps<{
        approvals?: ApprovalWorkloadRow[];
    }>(),
    { approvals: () => [] },
);

const approvals = computed<ApprovalWorkloadRow[]>(() => props.approvals ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    approvals.value.length === 0 ? 'empty' : 'ready',
);

const DeadlineCellRenderer = defineComponent({
    name: 'ApprovalDeadlineCellRenderer',
    props: {
        params: {
            type: Object as PropType<ICellRendererParams<ApprovalWorkloadRow>>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => {
            const row = cellProps.params.data;

            if (row === undefined || row === null) {
                return h('span', { class: 'text-muted-foreground' }, '—');
            }

            const label = formatDateTime(row.deadline_at);

            if (!row.is_overdue) {
                return h('span', {}, label);
            }

            return h(
                Badge,
                { variant: 'destructive', 'data-approval-overdue': '' },
                () =>
                    t('i18n.pages.approvals.index.overdue', {
                        value1: label,
                    }),
            );
        };
    },
});

function decideUrl(row: ApprovalWorkloadRow): string {
    return ApprovalsController.show.url({ approval: row.id });
}

function goToDecide(row: ApprovalWorkloadRow): void {
    router.visit(decideUrl(row));
}

const columnDefs = computed<ColDef<ApprovalWorkloadRow>[]>(() => [
    {
        colId: 'record',
        field: 'anchor_label',
        headerName: t('i18n.pages.approvals.index.process'),
        flex: 2,
        minWidth: 200,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: ApprovalWorkloadRow): string =>
                row.anchor_url ?? decideUrl(row),
        },
    },
    {
        colId: 'objectType',
        field: 'anchor_kind_label',
        headerName: t('i18n.pages.approvals.index.kind'),
        flex: 1,
        minWidth: 140,
    },
    {
        colId: 'transition',
        field: 'transition_label',
        headerName: t('i18n.pages.approvals.index.transition'),
        flex: 1,
        minWidth: 180,
    },
    {
        colId: 'stage',
        field: 'stage_label',
        headerName: t('i18n.pages.approvals.index.level'),
        width: 120,
    },
    {
        colId: 'deadline',
        field: 'deadline_at',
        headerName: t('i18n.pages.approvals.index.deadline'),
        flex: 1,
        minWidth: 180,
        cellRenderer: DeadlineCellRenderer,
    },
    {
        colId: 'onBehalfOf',
        headerName: t('i18n.pages.approvals.index.on_behalf_of'),
        flex: 1,
        minWidth: 180,
        valueGetter: (
            params: ValueGetterParams<ApprovalWorkloadRow>,
        ): string => {
            const label = params.data?.on_behalf_of_label ?? null;

            return label === null
                ? '—'
                : t('i18n.pages.approvals.index.acting_for', {
                      value1: label,
                  });
        },
    },
    actionsColumn<ApprovalWorkloadRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.approvals.index.decide'),
            variant: 'edit',
            testId: 'approval-decide',
            href: (row) => decideUrl(row),
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="PAGE_TITLE" />

        <Heading
            variant="small"
            :title="PAGE_TITLE"
            :description="PAGE_DESCRIPTION"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                empty-kind="no-records"
                skeleton-variant="list"
                :empty-title="EMPTY_TITLE"
                :empty-description="EMPTY_DESCRIPTION"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="approvals"
                    :is-row-activatable="() => true"
                    :aria-label="t('i18n.pages.approvals.index.my_approvals')"
                    @row-activate="goToDecide"
                />
            </div>
        </div>
    </ListPage>
</template>
