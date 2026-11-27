<script setup lang="ts">
defineOptions({ inheritAttrs: false });
import { router, usePage } from '@inertiajs/vue3';
import { Info, Trash2, X } from '@lucide/vue';
import { computed, onMounted, ref, useAttrs, useTemplateRef } from 'vue';
import RecordGridsController from '@/actions/App/Http/Controllers/Engine/RecordGridsController';
import RecordMergeController from '@/actions/App/Http/Controllers/Engine/RecordMergeController';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import TrashController from '@/actions/App/Http/Controllers/Engine/TrashController';
import { candidates as collaboratorCandidates } from '@/actions/App/Http/Controllers/Records/RecordCollaboratorsController';

import type { UndoableMerge } from '@/domains/business-data/data';
import { recordRouteKey } from '@/domains/business-data/data';
import { RecordTimeline } from '@/domains/business-data/ui-record-collaboration';
import { DynamicForm } from '@/domains/business-data/ui-record-field';
import type { FieldGroupRow } from '@/domains/business-data/ui-record-field';
import type { RecordObjectType, RecordPayload, RecordSaveScope } from '@/shared/business-data/types/records';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { hydrateObjectTypePreferences, useUserPreferences } from '@/shared/composables/useUserPreferences';
import { formatDateTime, formatIsoDate } from '@/shared/lib/formatDate';
import type { FieldDefinition } from '@/shared/types/fields';
import type { ObjectTypePreferences } from '@/shared/types/preferences';
import { emptyObjectTypePreferences } from '@/shared/types/preferences';

import type { SelectOption } from '@/shared/types/ui';
import AlertError from '@/shared/ui/AlertError.vue';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Alert, AlertDescription, AlertTitle } from '@/shared/ui/primitives/alert';
import { Button } from '@/shared/ui/primitives/button';
import { IconActionButton } from '@/shared/ui/primitives/icon-action-button';
import ReadOnlyNotice from '@/shared/ui/ReadOnlyNotice.vue';
import TextLink from '@/shared/ui/TextLink.vue';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import { useUserOptions } from '@/shared/workspace-access/data/useUserOptions';
import { useHiddenSections } from '../../composables/useHiddenSections';
import { useRecordDelete } from '../../data/useRecordDelete';
import { useRecordDuplicate } from '../../data/useRecordDuplicate';
import { useRecordForm } from '../../data/useRecordForm';
import { useRecordMerge } from '../../data/useRecordMerge';
import ConflictDialog from '../../ui/ConflictDialog.vue';
import RecordActionsMenu from '../../ui/RecordActionsMenu.vue';
import RecordCollaboratorsMenu from '../../ui/RecordCollaboratorsMenu.vue';
import RecordDeleteDialog from '../../ui/RecordDeleteDialog.vue';
import RecordDetailTabs from '../../ui/RecordDetailTabs.vue';
import RecordOwnerMenu from '../../ui/RecordOwnerMenu.vue';
import RecordPanelSettings from '../../ui/RecordPanelSettings.vue';
import RecordRelations from '../../ui/RecordRelations.vue';
import RecordWatchersMenu from '../../ui/RecordWatchersMenu.vue';
import { buildRecordPanels, RELATIONS_PANEL_ID } from '../../utils/recordPanels';
import type { RecordPanel } from '../../utils/recordPanels';

const { t } = useI18n();

const PENDING_RECORD_HINT = t('i18n.pages.records.form.reminders_and_history_become_available_after_the_first_save');

const page = usePage();

const props = withDefaults(
  defineProps<{
    mode: 'create' | 'edit';
    objectType: RecordObjectType;
    fieldDefinitions: FieldDefinition[];
    fieldGroups?: FieldGroupRow[];
    relocatedFields?: FieldDefinition[];
    record: RecordPayload | null;
    canUpdate?: boolean;
    trashed?: boolean;
    canPurge?: boolean;
    purgeOn?: string | null;
    deletionReason?: string | null;
    collaborators?: SelectOption[];
    hasRelationships?: boolean;
    undoableMerge?: UndoableMerge | null;
    preference?: ObjectTypePreferences;
  }>(),
  {
    relocatedFields: () => [],
    canUpdate: true,
    trashed: false,
    canPurge: false,
    purgeOn: null,
    deletionReason: null,
    collaborators: () => [],
    hasRelationships: false,
    undoableMerge: null,
    preference: () => emptyObjectTypePreferences(),
  },
);

hydrateObjectTypePreferences(props.objectType.id, props.preference);

const { canForObjectType } = usePermissions();

const listHref = computed<string>(() => RecordGridsController.index.url({ objectType: props.objectType.slug }));

const heading = computed<string>(() =>
  props.mode === 'create'
    ? t('i18n.pages.records.form.create', {
        value1: props.objectType.label,
      })
    : (props.record?.title ??
      props.record?.recordNumber ??
      t('i18n.pages.records.form.edit', {
        value1: props.objectType.label,
      })),
);

const description = computed<string>(() =>
  props.mode === 'create'
    ? t('i18n.pages.records.form.all_fields_for_the_new_entry_in', {
        value1: props.objectType.label,
      })
    : t('i18n.pages.records.form.edit_and_save_all_fields_of_this_record'),
);

usePageBreadcrumbs(() => [{ title: props.objectType.label, href: listHref.value }, { title: heading.value }]);

const isEditable = computed<boolean>(() => props.canUpdate && !props.trashed);

const mergedIntoId = computed<string | null>(() => props.record?.mergedIntoRecordId ?? null);

const mergeNoticeDismissed = ref<boolean>(false);

const undoDeadline = computed<string | null>(() => props.undoableMerge?.undoableUntil ?? null);

const isUndoWindowOpen = computed<boolean>(
  () => undoDeadline.value === null || new Date(undoDeadline.value).getTime() > Date.now(),
);

const showsMergeNotice = computed<boolean>(
  () =>
    !mergeNoticeDismissed.value &&
    props.undoableMerge !== null &&
    mergedIntoId.value === null &&
    isUndoWindowOpen.value,
);

const undoDeadlineHint = computed<string>(() =>
  undoDeadline.value === null
    ? t('i18n.pages.records.form.there_is_no_time_limit_for_reverting')
    : t('i18n.pages.records.form.undo_is_available_until', {
        value1: formatDateTime(undoDeadline.value),
      }),
);

const { undoing, undo } = useRecordMerge(() => undefined);

function onUndoMerge(): void {
  const merge = props.undoableMerge;

  if (merge === null || merge === undefined || props.record === null) {
    return;
  }

  void undo(props.record.id, merge.id).then((result) => {
    if (result !== null) {
      router.reload();
    }
  });
}

const { hidden: hiddenPanels, isVisible } = useHiddenSections(props.mode === 'edit' ? props.objectType.id : null);

const panels = computed<RecordPanel[]>(() =>
  buildRecordPanels(props.fieldDefinitions, props.fieldGroups ?? [], props.hasRelationships),
);

const canManagePanels = computed<boolean>(() => useUserPreferences().allows('panelVisibility', 'records'));

const panelSettingsOpen = ref<boolean>(false);

const extensionAttributes = ref<Record<string, unknown>>({});
const extensionUpdates = ref<Record<string, unknown>>({});
const extensionValidators = new Map<string, () => Record<string, string>>();
const registerValidator = (key: string, validator: () => Record<string, string>): (() => void) => {
  extensionValidators.set(key, validator);

  return () => {
    extensionValidators.delete(key);
  };
};

const {
  values,
  currentRecord,
  ownerId,
  collaboratorIds,
  errors,
  saving,
  conflictOpen,
  submit,
  hasChanges,
  changedValues,
  revert,
  saveAssignment,
  closeConflict,
  adoptRecord,
} = useRecordForm({
  mode: props.mode,
  objectType: props.objectType,
  record: props.record,
  collaboratorIds: props.collaborators.map((option) => option.value),
  attributes: () => extensionAttributes.value,
  updates: () => extensionUpdates.value,
  validateExtensions: () => Object.assign({}, ...[...extensionValidators.values()].map((validate) => validate())),
  onSaved: (saved) => {
    if (props.mode === 'create') {
      markSaved();
      router.visit(RecordsController.edit.url({ record: recordRouteKey(saved) }));

      return;
    }

    onDetailChanged();
  },
});

const {
  options: peopleOptions,
  loading: peopleLoading,
  load: loadPeople,
} = useUserOptions(props.record === null ? undefined : collaboratorCandidates.url({ record: props.record.id }));

onMounted(() => {
  if (props.mode === 'edit' && props.record !== null && !props.trashed) {
    void loadPeople();
  }
});

const collaboratorChoices = computed<SelectOption[]>(() =>
  peopleOptions.value.filter((option) => option.value !== ownerId.value),
);

const watcherChoices = computed<SelectOption[]>(() =>
  collaboratorChoices.value.filter((option) => !collaboratorIds.value.includes(option.value)),
);

function onAssignmentChanged(): void {
  void saveAssignment();
}

function onOwnerChanged(): void {
  collaboratorIds.value = collaboratorIds.value.filter((id) => id !== ownerId.value);

  onAssignmentChanged();
}

const deletion = useRecordDelete(() => {
  router.visit(listHref.value);
});

const { duplicate } = useRecordDuplicate((copy) => {
  router.visit(RecordsController.edit.url({ record: recordRouteKey(copy) }));
});

const deleteDescription = computed<string>(() => {
  const number = deletion.pending.value?.recordNumber ?? null;
  const subject = number === null ? t('i18n.pages.records.form.the_record') : `„${number}“`;

  return t('i18n.pages.records.form.will_be_deleted_and_can_be_restored_from_the', { value1: subject });
});

function onDuplicate(): void {
  if (props.record !== null) {
    void duplicate(props.record);
  }
}

function onMerge(): void {
  if (props.record !== null) {
    router.visit(RecordMergeController.show.url({ record: props.record.id }));
  }
}

function onDelete(): void {
  if (props.record !== null) {
    deletion.request(props.record);
  }
}

const purgeNotice = computed<string>(() =>
  props.purgeOn === null
    ? t('i18n.pages.records.form.this_record_was_deleted_it_will_remain_in_the')
    : t('i18n.pages.records.form.this_record_has_been_deleted_permanent_deletion_on', {
        value1: formatIsoDate(props.purgeOn),
      }),
);

const purgeDialogOpen = ref<boolean>(false);
const purging = ref<boolean>(false);

function confirmPurge(): void {
  if (props.record === null) {
    return;
  }

  purging.value = true;

  router.delete(TrashController.destroy.url({ id: props.record.id }), {
    onFinish: () => {
      purging.value = false;
      purgeDialogOpen.value = false;
    },
    onSuccess: () => router.visit(listHref.value),
  });
}

const { promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () =>
    props.mode === 'create'
      ? { ...values.value, ...extensionAttributes.value }
      : {
          data: changedValues(),
          updates: { ...extensionUpdates.value },
        },
  backHref: () => listHref.value,
});

let isFormMounted = false;
const pendingSeeds: Record<string, unknown>[] = [];

function isBlankValue(value: unknown): boolean {
  return value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0);
}

function applySeed(data: Record<string, unknown>): void {
  const blankEntries = Object.entries(data).filter(([key]) => isBlankValue(values.value[key]));

  values.value = { ...values.value, ...Object.fromEntries(blankEntries) };
}

function seedValues(data: Record<string, unknown>): void {
  if (props.mode !== 'create') {
    return;
  }

  if (!isFormMounted) {
    pendingSeeds.push(data);

    return;
  }

  applySeed(data);
}

onMounted(() => {
  isFormMounted = true;
  pendingSeeds.splice(0).forEach(applySeed);
});

function setValue(key: string, value: unknown): void {
  values.value = { ...values.value, [key]: value };
}

async function saveSection(scope: RecordSaveScope): Promise<boolean> {
  const isSaved = await submit(scope);

  if (isSaved) {
    scope.updates.forEach((key) => {
      delete extensionUpdates.value[key];
    });
  }

  return isSaved;
}

function saveGroup(fields: string[]): void {
  void saveSection({ fields, updates: [] });
}

function cancelGroup(fields: string[]): void {
  if (props.mode === 'create') {
    requestLeave();

    return;
  }

  revert(fields);
}

function reloadRecord(): void {
  closeConflict();
  router.reload();
}

const timeline = useTemplateRef<InstanceType<typeof RecordTimeline>>('timeline');

function onDetailChanged(): void {
  timeline.value?.reload();
}

const attrs = useAttrs();

const extensionContext = computed(() => ({
  ...page.props,
  ...attrs,
  ...props,
  record: currentRecord.value,
  editable: isEditable.value,
  errors: errors.value,
  values: values.value,
  setValue,
  saving: saving.value,
  hasChanges,
  saveSection,
  cancelSection: cancelGroup,
  attributes: extensionAttributes.value,
  updates: extensionUpdates.value,
  registerValidator,
  seedValues,
  adoptRecord,
  changed: onDetailChanged,
  conflict: () => {
    conflictOpen.value = true;
  },
}));
</script>

<template>
  <PageShell :title="heading" :description="description">
    <template #actions>
      <div
        v-if="props.mode === 'edit' && props.record !== null"
        data-record-people
        class="flex flex-wrap items-center gap-1 pt-2"
      >
        <template v-if="!props.trashed">
          <RecordOwnerMenu
            v-model="ownerId"
            :options="peopleOptions"
            :disabled="!isEditable || peopleLoading"
            @update:model-value="onOwnerChanged"
          />

          <RecordCollaboratorsMenu
            v-model="collaboratorIds"
            :options="collaboratorChoices"
            :disabled="!isEditable || peopleLoading"
            @update:model-value="onAssignmentChanged"
          />

          <RecordWatchersMenu
            :record-id="props.record.id"
            :options="watcherChoices"
            :disabled="peopleLoading || !canForObjectType(props.objectType.slug, 'watchers.manage')"
          />
        </template>

        <RecordActionsMenu
          :trashed="props.trashed"
          :can-delete="canForObjectType(props.objectType.slug, 'delete')"
          :can-merge="canForObjectType(props.objectType.slug, 'merge')"
          :can-manage-panels="canManagePanels"
          @manage-panels="panelSettingsOpen = true"
          @duplicate="onDuplicate"
          @merge="onMerge"
          @delete="onDelete"
        />
      </div>
    </template>

    <AlertError
      v-if="mergedIntoId !== null"
      :title="t('i18n.pages.records.form.this_record_was_merged')"
      data-record-merged
    >
      {{ t('i18n.pages.records.form.its_content_lives_on_in_the_target_record') }}
      <TextLink :href="RecordsController.edit.url({ record: mergedIntoId })">
        {{ t('i18n.pages.records.form.go_to_target_record') }}
      </TextLink>
    </AlertError>

    <AlertError
      v-else-if="props.trashed"
      :title="t('i18n.pages.records.form.this_record_was_deleted')"
      data-record-trashed
    >
      <div class="flex flex-col gap-1 pr-10">
        <span data-record-purge-notice>{{ purgeNotice }}</span>
        <span v-if="props.deletionReason" data-record-deletion-reason>
          {{ t('i18n.pages.records.form.reason') }}
          {{ props.deletionReason }}
        </span>
        <span>
          {{ t('i18n.pages.records.form.actions_are_disabled_restore_the_record_from_the_trash') }}
        </span>
      </div>

      <IconActionButton
        v-if="props.canPurge"
        :icon="Trash2"
        :label="t('i18n.pages.records.form.delete_permanently')"
        variant="destructive"
        test-id="record-purge"
        class="absolute top-2 right-2"
        @click="purgeDialogOpen = true"
      />
    </AlertError>

    <ReadOnlyNotice
      v-else-if="!props.canUpdate"
      :reason="t('i18n.pages.records.form.you_do_not_have_permission_to_change_this_record')"
      data-record-readonly
    />

    <UiExtensionPoint name="records.form" :context="extensionContext" />

    <div class="grid items-start gap-6 lg:grid-cols-3">
      <div data-record-detail-main class="flex flex-col gap-6">
        <RecordRelations
          v-if="props.record !== null && props.hasRelationships && isVisible(RELATIONS_PANEL_ID)"
          :record-id="props.record.id"
          :object-type-id="props.objectType.id"
          :readonly="props.trashed || !props.canUpdate"
        />

        <fieldset :disabled="!isEditable">
          <DynamicForm
            v-model="values"
            :fields="props.fieldDefinitions"
            :creating="props.mode === 'create'"
            :groups="props.fieldGroups ?? []"
            :errors="errors"
            :object-type-id="props.objectType.id"
            :hidden-sections="hiddenPanels"
          >
            <template #actions="{ fields }">
              <FormActions
                v-if="isEditable && hasChanges(fields)"
                type="button"
                dirty
                :processing="saving"
                @save="saveGroup(fields)"
                @cancel="cancelGroup(fields)"
              />
            </template>
          </DynamicForm>
        </fieldset>

        <UiExtensionPoint name="records.form.sections" :context="extensionContext" />
      </div>

      <div data-record-detail-aside class="flex flex-col gap-6 lg:col-span-2">
        <template v-if="props.record !== null">
          <RecordDetailTabs
            :key="props.record.id"
            :record-id="props.record.id"
            :readonly="props.trashed"
            @changed="onDetailChanged"
          />

          <Alert v-if="showsMergeNotice" data-record-merge-undo>
            <AlertTitle>{{ t('i18n.pages.records.form.this_record_has_absorbed_another_record') }}</AlertTitle>
            <AlertDescription class="flex flex-wrap items-center gap-3">
              <span>
                {{ t('i18n.pages.records.form.the_merge_can_still_be_reverted_values_you_have') }}
                <span data-record-merge-undo-deadline>
                  {{ undoDeadlineHint }}
                </span>
              </span>
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="undoing"
                data-record-merge-undo-button
                @click="onUndoMerge"
              >
                {{ t('i18n.pages.records.form.undo_merge') }}
              </Button>
            </AlertDescription>

            <IconActionButton
              :icon="X"
              :label="t('i18n.pages.records.form.dismiss_notice')"
              test-id="record-merge-undo-dismiss"
              class="absolute top-2 right-2"
              @click="mergeNoticeDismissed = true"
            />
          </Alert>

          <RecordTimeline ref="timeline" :record-id="props.record.id" :fields="props.fieldDefinitions" />
        </template>

        <Alert v-else role="status" data-record-detail-pending>
          <Info />
          <AlertDescription>
            {{ PENDING_RECORD_HINT }}
          </AlertDescription>
        </Alert>
      </div>
    </div>

    <RecordPanelSettings
      v-if="props.mode === 'edit' && canManagePanels"
      :open="panelSettingsOpen"
      :panels="panels"
      :object-type-id="props.objectType.id"
      @close="panelSettingsOpen = false"
    />

    <RecordDeleteDialog
      v-model:reason="deletion.reason.value"
      :open="deletion.isOpen.value"
      :description="deleteDescription"
      :pending="deletion.deleting.value"
      :requires-reason="props.objectType.requiresDeletionReason"
      :error="deletion.reasonError.value"
      @confirm="deletion.confirm"
      @cancel="deletion.cancel"
    />

    <ConfirmDialog
      v-model:open="purgeDialogOpen"
      :title="t('i18n.pages.records.form.permanently_delete_record')"
      :description="t('i18n.pages.records.form.the_record_will_be_permanently_deleted_and_cannot_be')"
      :confirm-label="t('i18n.pages.records.form.delete_permanently')"
      variant="destructive"
      :pending="purging"
      @confirm="confirmPurge"
    />

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />

    <ConflictDialog :open="conflictOpen" @close="closeConflict" @reload="reloadRecord" />
  </PageShell>
</template>
