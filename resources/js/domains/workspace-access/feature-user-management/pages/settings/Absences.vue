<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueFormatterParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import AbsencesController from '@/actions/App/Http/Controllers/Settings/AbsencesController';
import { useI18n } from '@/shared/composables/useI18n';
import { useListSelection } from '@/shared/composables/useListSelection';
import { useRowDeletion } from '@/shared/composables/useRowDeletion';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { isErrorBag } from '@/shared/lib/errorResponse';
import { formatIsoDate } from '@/shared/lib/formatDate';
import type { SelectOption } from '@/shared/types/ui';
import CardSection from '@/shared/ui/CardSection.vue';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import { actionsColumn } from '@/shared/ui/data-grid/actions';
import DataGrid from '@/shared/ui/data-grid/DataGrid.vue';
import SelectionBulkBar from '@/shared/ui/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/shared/ui/data-grid/selectionColumn';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import Heading from '@/shared/ui/Heading.vue';
import { Alert, AlertDescription } from '@/shared/ui/primitives/alert';
import { Button } from '@/shared/ui/primitives/button';
import { Card, CardContent } from '@/shared/ui/primitives/card';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Input } from '@/shared/ui/primitives/input';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import ViewStates from '@/shared/ui/ViewStates.vue';

const { t } = useI18n();

interface AbsenceSubject {
  id: string;
  name: string;
}

interface DelegationRow {
  id: string;
  delegateId: string;
  delegateName: string;
  startsAt: string;
  endsAt: string;
  can_update: boolean;
  can_delete: boolean;
  delete_reason: string | null;
}

const props = withDefaults(
  defineProps<{
    subject: AbsenceSubject;
    isOwnSubject: boolean;
    canManage: boolean;
    delegations?: DelegationRow[];
    delegateOptions?: SelectOption[];
    manageableUserOptions?: SelectOption[];
  }>(),
  {
    delegations: () => [],
    delegateOptions: () => [],
    manageableUserOptions: () => [],
  },
);

const deniedReason = t('i18n.pages.settings.absences.no_permission');

const page = usePage();

const subjectError = computed<string | undefined>(() =>
  isErrorBag(page.props.errors) ? page.props.errors.userId : undefined,
);

const delegations = computed<DelegationRow[]>(() => props.delegations ?? []);

const subjectId = ref<string>(props.subject.id);

const editingId = ref<string | null>(null);
const delegateId = ref<string>('');
const startsAt = ref<string>('');
const endsAt = ref<string>('');

const formAction = computed(() =>
  editingId.value === null
    ? AbsencesController.store.form()
    : AbsencesController.update.form({ absence: editingId.value }),
);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    delegateId: delegateId.value,
    startsAt: startsAt.value,
    endsAt: endsAt.value,
  }),
  backHref: AbsencesController.index.url(),
});

watch(
  () => props.subject.id,
  (value) => {
    subjectId.value = value;
  },
);

watch(subjectId, (value) => {
  if (value === props.subject.id) {
    return;
  }

  router.visit(AbsencesController.index.url({ query: { user: value } }));
});

function abortSubjectSwitch(): void {
  subjectId.value = props.subject.id;

  cancelLeave();
}

function resetForm(): void {
  editingId.value = null;
  delegateId.value = '';
  startsAt.value = '';
  endsAt.value = '';

  markSaved();
}

function loadRow(row: DelegationRow): void {
  editingId.value = row.id;
  delegateId.value = row.delegateId;
  startsAt.value = row.startsAt;
  endsAt.value = row.endsAt;

  markSaved();
}

function withSelections(data: Record<string, FormDataConvertible>): Record<string, FormDataConvertible> {
  return {
    ...data,
    delegateId: delegateId.value,
    userId: props.subject.id,
  };
}

const selection = useListSelection();

watch(delegations, (rows) => selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)));

const { pendingDelete, deletePending, deleteDialogOpen, confirmDelete } = useRowDeletion<DelegationRow>({
  url: (row) => AbsencesController.destroy.url({ absence: row.id }),
});

const deputyDescription = computed<string>(() =>
  editingId.value === null
    ? t('i18n.pages.settings.absences.enter_a_new_absence_period')
    : t('i18n.pages.settings.absences.edit_the_selected_absence_period'),
);

const deleteDescription = computed<string>(() =>
  pendingDelete.value === null
    ? ''
    : t('i18n.pages.settings.absences.the_period_from_to_will_be_deleted', {
        value1: formatIsoDate(pendingDelete.value.startsAt),
        value2: formatIsoDate(pendingDelete.value.endsAt),
      }),
);

function bulkDeleteDelegations(): void {
  router.post(
    AbsencesController.bulkDestroy.url(),
    { ids: selection.ids.value, userId: props.subject.id },
    {
      preserveScroll: true,
      onSuccess: () => selection.clear(),
    },
  );
}

const columnDefs = computed<ColDef<DelegationRow>[]>(() => [
  selectionColumn<DelegationRow>(selection, (row) => row.can_delete),
  {
    colId: 'delegateName',
    field: 'delegateName',
    headerName: t('i18n.pages.settings.absences.deputy'),
    flex: 1,
    minWidth: 180,
  },
  {
    colId: 'startsAt',
    field: 'startsAt',
    headerName: t('i18n.pages.settings.absences.from'),
    width: 140,
    minWidth: 120,
    valueFormatter: (params: ValueFormatterParams<DelegationRow>): string =>
      params.data === undefined ? '' : formatIsoDate(params.data.startsAt),
  },
  {
    colId: 'endsAt',
    field: 'endsAt',
    headerName: t('i18n.pages.settings.absences.to'),
    width: 140,
    minWidth: 120,
    valueFormatter: (params: ValueFormatterParams<DelegationRow>): string =>
      params.data === undefined ? '' : formatIsoDate(params.data.endsAt),
  },
  actionsColumn<DelegationRow>([
    {
      icon: Pencil,
      label: t('i18n.pages.settings.absences.edit'),
      variant: 'edit',
      testId: 'absence-edit',
      onClick: (row) => loadRow(row),
      isDisabled: (row) => !row.can_update,
      disabledReason: () => deniedReason,
    },
    {
      icon: Trash2,
      label: t('i18n.pages.settings.absences.delete'),
      variant: 'destructive',
      testId: 'absence-delete',
      onClick: (row) => (pendingDelete.value = row),
      isDisabled: (row) => !row.can_delete,
      disabledReason: (row) => row.delete_reason ?? deniedReason,
    },
  ]),
]);
</script>

<template>
  <div class="flex flex-col gap-6">
    <Head :title="t('i18n.pages.settings.absences.absence')" />

    <Heading
      variant="small"
      :title="t('i18n.pages.settings.absences.absence')"
      :description="t('i18n.pages.settings.absences.choose_who_covers_for_you_during_an_absence_approvals')"
    />

    <Card data-absence-settings>
      <CardContent class="flex flex-col gap-6">
        <CardSection
          v-if="canManage"
          :title="t('i18n.pages.settings.absences.manage_absence_for')"
          :description="t('i18n.pages.settings.absences.select_a_person_to_manage_their_absence_periods')"
          data-absence-section="subject"
        >
          <FormField
            for="absence-subject"
            :label="t('i18n.pages.settings.absences.person')"
            :error="subjectError"
            class="sm:max-w-sm"
          >
            <Combobox
              id="absence-subject"
              v-model="subjectId"
              :options="props.manageableUserOptions"
              :search-placeholder="t('i18n.pages.settings.absences.search_people')"
              :aria-label="t('i18n.pages.settings.absences.manage_absence_for')"
            />
          </FormField>
        </CardSection>

        <CardSection
          :title="t('i18n.pages.settings.absences.deputy')"
          :description="deputyDescription"
          data-absence-section="deputy"
        >
          <Alert v-if="!isOwnSubject" data-testid="absence-audit-hint">
            <AlertDescription>
              {{ t('i18n.pages.settings.absences.you_are_managing_the_absence_of') }}
              {{ props.subject.name }}{{ t('i18n.pages.settings.absences.every_change_is_logged_under_your_name') }}
            </AlertDescription>
          </Alert>

          <div v-if="editingId !== null" class="flex justify-end">
            <Button type="button" variant="outline" size="sm" data-testid="absence-new" @click="resetForm">
              {{ t('i18n.pages.settings.absences.new_period') }}
            </Button>
          </div>

          <Form
            v-bind="formAction"
            :transform="withSelections"
            :on-success="resetForm"
            class="flex flex-col gap-4"
            v-slot="{ errors, processing }"
          >
            <div class="grid gap-4 sm:grid-cols-2">
              <FormField
                for="absence-delegate"
                :label="t('i18n.pages.settings.absences.deputy')"
                :error="errors.delegateId"
              >
                <Combobox
                  id="absence-delegate"
                  v-model="delegateId"
                  :options="props.delegateOptions"
                  :search-placeholder="t('i18n.pages.settings.absences.search_people')"
                  :empty-label="t('i18n.pages.settings.absences.there_are_no_other_people')"
                  :aria-label="t('i18n.pages.settings.absences.deputy')"
                />
              </FormField>

              <FormField
                for="absence-starts-at"
                :label="t('i18n.pages.settings.absences.from')"
                :error="errors.startsAt"
              >
                <Input id="absence-starts-at" v-model="startsAt" name="startsAt" type="date" required />
              </FormField>

              <FormField for="absence-ends-at" :label="t('i18n.pages.settings.absences.to')" :error="errors.endsAt">
                <Input id="absence-ends-at" v-model="endsAt" name="endsAt" type="date" required />
              </FormField>
            </div>

            <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
          </Form>
        </CardSection>

        <CardSection
          :title="t('i18n.pages.settings.absences.saved_periods')"
          :description="t('i18n.pages.settings.absences.periods_must_not_overlap_consecutive_periods_are_allowed')"
          data-absence-section="periods"
        >
          <SelectionBulkBar
            :count="selection.count.value"
            :delete-label="t('i18n.pages.settings.absences.delete_periods')"
            @delete="bulkDeleteDelegations"
            @clear="selection.clear"
          />

          <div v-if="delegations.length === 0" class="relative min-h-40" data-testid="absence-empty">
            <ViewStates
              state="empty"
              :empty-title="t('i18n.pages.settings.absences.no_absences_yet')"
              :empty-description="t('i18n.pages.settings.absences.no_absence_period_has_been_saved_yet')"
            />
          </div>

          <div v-else class="h-80">
            <DataGrid
              class="h-full w-full"
              :column-defs="columnDefs"
              :row-data="delegations"
              :is-row-activatable="(row: DelegationRow) => row.can_update"
              :aria-label="t('i18n.pages.settings.absences.absence_periods')"
              @row-activate="loadRow"
            />
          </div>
        </CardSection>
      </CardContent>
    </Card>

    <ConfirmDialog
      v-model:open="deleteDialogOpen"
      :title="t('i18n.pages.settings.absences.delete_period')"
      :description="deleteDescription"
      :confirm-label="t('i18n.pages.settings.absences.delete')"
      variant="destructive"
      :pending="deletePending"
      @confirm="confirmDelete"
    />

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="abortSubjectSwitch" />
  </div>
</template>
