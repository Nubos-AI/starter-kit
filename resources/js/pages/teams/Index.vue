<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CornerUpRight, Pencil, Trash2 } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import ViewStates from '@/components/engine/ViewStates.vue';
import InputError from '@/components/InputError.vue';
import { TeamNameCellRenderer } from '@/components/teams/TeamNameCellRenderer';
import TeamReparentDialog from '@/components/teams/TeamReparentDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { usePermissions } from '@/composables/usePermissions';
import { useRowDeletion } from '@/composables/useRowDeletion';
import type { TeamTreeNode } from '@/types/teams';

const { t } = useI18n();

const props = defineProps<{
    nodes: TeamTreeNode[];
}>();

const { can } = usePermissions();

const deniedReason = t('i18n.pages.teams.index.no_permission');

const page = usePage();

const reparentSubject = ref<TeamTreeNode | null>(null);
const isReparentOpen = ref<boolean>(false);
const isProcessing = ref<boolean>(false);

const {
    pendingDelete,
    deletePending,
    deleteDialogOpen: confirmOpen,
    requestDelete,
    confirmDelete,
} = useRowDeletion<TeamTreeNode>({
    url: (node) => TeamsController.destroy.url({ team: node.id }),
});

function isErrorBag(value: unknown): value is Record<string, string> {
    return typeof value === 'object' && value !== null;
}

const errors = computed<Record<string, string>>(() =>
    isErrorBag(page.props.errors) ? page.props.errors : {},
);

const reparentError = computed<string | null>(
    () => errors.value.parent_team_id ?? null,
);

const deleteError = computed<string | undefined>(() => errors.value.team);

const listState = computed<'empty' | 'ready'>(() =>
    props.nodes.length === 0 ? 'empty' : 'ready',
);

const emptyDescription = computed<string>(() =>
    can('teams.create')
        ? t('i18n.pages.teams.index.create_the_first_team_to_start_the_tree')
        : t('i18n.pages.teams.index.no_teams_available'),
);

const confirmDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.teams.index.the_team_will_be_permanently_deleted_this_action_cannot',
              { value1: pendingDelete.value.name },
          ),
);

const selection = useListSelection();

watch(
    () => props.nodes,
    (rows) =>
        selection.prune(
            rows.filter((row) => row.can_delete).map((row) => row.id),
        ),
);

function bulkDeleteTeams(): void {
    router.post(
        TeamsController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

function goToCreate(): void {
    router.visit(TeamsController.create.url());
}

function onEdit(node: TeamTreeNode): void {
    router.visit(TeamsController.edit.url({ team: node.id }));
}

function onReparent(node: TeamTreeNode): void {
    reparentSubject.value = node;
    isReparentOpen.value = true;
}

function onReparentSubmit(parentTeamId: string | null): void {
    const subject = reparentSubject.value;

    if (subject === null) {
        return;
    }

    isProcessing.value = true;

    router.put(
        TeamsController.reparent.url({ team: subject.id }),
        { parent_team_id: parentTeamId },
        {
            preserveScroll: true,
            onSuccess: () => {
                isReparentOpen.value = false;
                reparentSubject.value = null;
            },
            onFinish: () => {
                isProcessing.value = false;
            },
        },
    );
}

const columnDefs = computed<ColDef<TeamTreeNode>[]>(() => [
    selectionColumn<TeamTreeNode>(selection, (row) => row.can_delete),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.teams.index.team'),
        flex: 2,
        minWidth: 220,
        cellRenderer: TeamNameCellRenderer,
        cellRendererParams: {
            href: (row: TeamTreeNode): string | undefined =>
                can('teams.update') && row.can_update
                    ? TeamsController.edit.url({ team: row.id })
                    : undefined,
        },
    },
    {
        colId: 'slug',
        field: 'slug',
        headerName: t('i18n.pages.teams.index.code'),
        flex: 1,
        minWidth: 140,
    },
    actionsColumn<TeamTreeNode>([
        {
            icon: Pencil,
            label: t('i18n.pages.teams.index.edit'),
            variant: 'edit',
            testId: 'team-edit',
            onClick: (row) => onEdit(row),
            isDisabled: (row) => !can('teams.update') || !row.can_update,
            disabledReason: (row) => row.update_reason ?? deniedReason,
        },
        {
            icon: CornerUpRight,
            label: t('i18n.pages.teams.index.move'),
            variant: 'info',
            testId: 'team-reparent',
            onClick: (row) => onReparent(row),
            isDisabled: (row) => !can('teams.reparent') || !row.can_reparent,
            disabledReason: (row) => row.reparent_reason ?? deniedReason,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.teams.index.delete'),
            variant: 'destructive',
            testId: 'team-delete',
            onClick: (row) => requestDelete(row),
            isDisabled: (row) => !can('teams.delete') || !row.can_delete,
            disabledReason: (row) => row.delete_reason ?? deniedReason,
        },
    ]),
]);
</script>

<template>
    <ListPage
        :title="t('i18n.pages.teams.index.teams')"
        :description="
            t(
                'i18n.pages.teams.index.the_team_tree_determines_user_assignments_and_how_far',
            )
        "
    >
        <template #actions>
            <CreateButton
                v-if="can('teams.create')"
                :href="TeamsController.create.url()"
                :label="t('i18n.pages.teams.index.create_team')"
            />
        </template>

        <InputError data-team-error :message="deleteError" />

        <SelectionBulkBar
            :count="selection.count.value"
            :delete-label="t('i18n.pages.teams.index.delete_teams')"
            @delete="bulkDeleteTeams"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                :empty-kind="
                    can('teams.create') ? 'no-records' : 'empty-column'
                "
                skeleton-variant="list"
                :empty-title="t('i18n.pages.teams.index.no_teams_yet')"
                :empty-description="emptyDescription"
                :create-label="t('i18n.pages.teams.index.create_team')"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 flex-1">
                <DataGrid
                    preference-key="teams"
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="props.nodes"
                    :default-col-def="{ sortable: false }"
                    :is-row-activatable="
                        (row: TeamTreeNode) =>
                            can('teams.update') && row.can_update
                    "
                    :aria-label="t('i18n.pages.teams.index.teams')"
                    @row-activate="onEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('i18n.pages.teams.index.delete_team')"
            :description="confirmDescription"
            :confirm-label="t('i18n.pages.teams.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />

        <TeamReparentDialog
            v-model:open="isReparentOpen"
            :subject="reparentSubject"
            :nodes="props.nodes"
            :error-message="reparentError"
            :processing="isProcessing"
            @submit="onReparentSubmit"
        />
    </ListPage>
</template>
