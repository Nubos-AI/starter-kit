<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef } from 'ag-grid-community';
import { computed } from 'vue';
import SkillsController from '@/actions/App/Http/Controllers/Skills/SkillsController';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';

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

function goToCreate(): void {
  router.visit(SkillsController.create.url());
}

function goToEdit(row: SkillRow): void {
  router.visit(SkillsController.edit.url({ skill: row.id }));
}

function destroyUrl(row: SkillRow): string {
  return SkillsController.destroy.url({ skill: row.id });
}

function bulkDestroyUrl(): string {
  return SkillsController.bulkDestroy.url();
}

const columns = computed<ColDef<SkillRow>[]>(() => [
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.pages.skills.index.name'),
    flex: 1,
    minWidth: 200,
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: SkillRow): string | undefined =>
        row.can_update ? SkillsController.edit.url({ skill: row.id }) : undefined,
    },
  },
  {
    colId: 'users_count',
    field: 'users_count',
    headerName: t('i18n.pages.skills.index.assigned_users'),
    width: 200,
    minWidth: 160,
  },
]);

const rowOpen: ListGridRowOpen<SkillRow> = {
  label: t('i18n.pages.skills.index.edit'),
  testId: 'skill-edit',
  isAllowed: (row) => row.can_update,
  reason: () => deniedReason,
};

const rowDelete: ListGridRowDelete<SkillRow> = {
  label: t('i18n.pages.skills.index.delete'),
  testId: 'skill-delete',
  reason: (row) => row.delete_reason ?? deniedReason,
};

const copy = computed<ListGridCopy<SkillRow>>(() => ({
  deleteSelected: t('i18n.pages.skills.index.delete_skills'),
  emptyTitle: t('i18n.pages.skills.index.no_skills_yet'),
  emptyDescription: t('i18n.pages.skills.index.create_the_first_skill_to_assign_responsibilities'),
  create: t('i18n.pages.skills.index.create_skill'),
  deleteTitle: t('i18n.pages.skills.index.delete_skill'),
  deleteDescription: (row) =>
    row === null ? '' : t('i18n.pages.skills.index.the_skill_will_be_deleted_and_will_no_longer', { value1: row.name }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.skills.index.skills')"
    :description="t('i18n.pages.skills.index.skills_describe_what_a_person_is_responsible_for_task')"
  >
    <template #actions>
      <CreateButton
        v-if="can('skills.create')"
        :href="SkillsController.create.url()"
        :label="t('i18n.pages.skills.index.create_skill')"
      />
    </template>

    <ListGrid
      :rows="skills"
      :columns="columns"
      :grid-label="t('i18n.pages.skills.index.skills')"
      :can-create="can('skills.create')"
      :copy="copy"
      :destroy-url="destroyUrl"
      :bulk-destroy-url="bulkDestroyUrl"
      :row-open="rowOpen"
      :row-delete="rowDelete"
      @create="goToCreate"
      @open="goToEdit"
    />
  </ListPage>
</template>
