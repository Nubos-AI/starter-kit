<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import SkillsController from '@/actions/App/Http/Controllers/Skills/SkillsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import ViewStates from '@/components/engine/ViewStates.vue';
import Heading from '@/components/Heading.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { usePermissions } from '@/composables/usePermissions';

const { t } = useI18n();

interface SkillRow {
    id: string;
    name: string;
    users_count: number;
    can_update: boolean;
    can_delete: boolean;
    delete_reason: string | null;
}

const props = withDefaults(
    defineProps<{
        skills?: SkillRow[];
    }>(),
    { skills: () => [] },
);

const { can } = usePermissions();

const deniedReason = t('i18n.pages.skills.index.no_permission');

const skills = computed<SkillRow[]>(() => props.skills ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    skills.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(skills, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const pendingDelete = ref<SkillRow | null>(null);
const deletePending = ref<boolean>(false);

const deleteDialogOpen = computed<boolean>({
    get: () => pendingDelete.value !== null,
    set: (value) => {
        if (!value) {
            pendingDelete.value = null;
        }
    },
});

const deleteDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.skills.index.the_skill_will_be_deleted_and_will_no_longer',
              { value1: pendingDelete.value.name },
          ),
);

function bulkDeleteSkills(): void {
    router.post(
        SkillsController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

function confirmDelete(): void {
    const row = pendingDelete.value;

    if (row === null) {
        return;
    }

    deletePending.value = true;

    router.delete(SkillsController.destroy.url({ skill: row.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletePending.value = false;
            pendingDelete.value = null;
        },
    });
}

function goToCreate(): void {
    router.visit(SkillsController.create.url());
}

function goToEdit(row: SkillRow): void {
    router.visit(SkillsController.edit.url({ skill: row.id }));
}

const columnDefs = computed<ColDef<SkillRow>[]>(() => [
    selectionColumn<SkillRow>(selection, (row) => row.can_delete),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.skills.index.name'),
        flex: 1,
        minWidth: 200,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: SkillRow): string | undefined =>
                row.can_update
                    ? SkillsController.edit.url({ skill: row.id })
                    : undefined,
        },
    },
    {
        colId: 'users_count',
        field: 'users_count',
        headerName: t('i18n.pages.skills.index.assigned_users'),
        width: 200,
        minWidth: 160,
    },
    actionsColumn<SkillRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.skills.index.edit'),
            variant: 'edit',
            testId: 'skill-edit',
            href: (row) => SkillsController.edit.url({ skill: row.id }),
            isDisabled: (row) => !row.can_update,
            disabledReason: () => deniedReason,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.skills.index.delete'),
            variant: 'destructive',
            testId: 'skill-delete',
            onClick: (row) => (pendingDelete.value = row),
            isDisabled: (row) => !row.can_delete,
            disabledReason: (row) => row.delete_reason ?? deniedReason,
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.skills.index.skills')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.skills.index.skills')"
                :description="
                    t(
                        'i18n.pages.skills.index.skills_describe_what_a_person_is_responsible_for_task',
                    )
                "
            />
            <CreateButton
                v-if="can('skills.create')"
                :href="SkillsController.create.url()"
                :label="t('i18n.pages.skills.index.create_skill')"
            />
        </div>

        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Fähigkeiten löschen"
            @delete="bulkDeleteSkills"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                :empty-kind="
                    can('skills.create') ? 'no-records' : 'empty-column'
                "
                skeleton-variant="list"
                empty-title="Noch keine Fähigkeiten"
                empty-description="Legen Sie die erste Fähigkeit an, um Benutzern Zuständigkeiten zuzuordnen."
                create-label="Fähigkeit anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="skills"
                    :is-row-activatable="(row: SkillRow) => row.can_update"
                    :aria-label="t('i18n.pages.skills.index.skills')"
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="t('i18n.pages.skills.index.delete_skill')"
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.skills.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
