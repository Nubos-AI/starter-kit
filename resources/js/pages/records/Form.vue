<script setup lang="ts">
defineOptions({ inheritAttrs: false });
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Trash2, X } from '@lucide/vue';
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import RecordGridsController from '@/actions/App/Http/Controllers/Engine/RecordGridsController';
import RecordMergeController from '@/actions/App/Http/Controllers/Engine/RecordMergeController';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import TrashController from '@/actions/App/Http/Controllers/Engine/TrashController';
import { candidates as collaboratorCandidates } from '@/actions/App/Http/Controllers/Records/RecordCollaboratorsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DynamicForm from '@/components/DynamicForm.vue';
import ConflictDialog from '@/components/engine/ConflictDialog.vue';
import RecordActionsMenu from '@/components/engine/records/RecordActionsMenu.vue';
import RecordCollaboratorsMenu from '@/components/engine/records/RecordCollaboratorsMenu.vue';
import RecordDeleteDialog from '@/components/engine/records/RecordDeleteDialog.vue';
import RecordDetailTabs from '@/components/engine/records/RecordDetailTabs.vue';
import RecordOwnerMenu from '@/components/engine/records/RecordOwnerMenu.vue';
import RecordPanelSettings from '@/components/engine/records/RecordPanelSettings.vue';
import RecordRelations from '@/components/engine/records/RecordRelations.vue';
import RecordWatchersMenu from '@/components/engine/records/RecordWatchersMenu.vue';
import RecordTimeline from '@/components/engine/timeline/RecordTimeline.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { IconActionButton } from '@/components/ui/icon-action-button';

import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useHiddenSections } from '@/composables/useHiddenSections';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { useRecordDelete } from '@/composables/useRecordDelete';
import { useRecordDuplicate } from '@/composables/useRecordDuplicate';
import { useRecordForm } from '@/composables/useRecordForm';
import { useRecordMerge } from '@/composables/useRecordMerge';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { useUserOptions } from '@/composables/useUserOptions';
import {
    hydrateObjectTypePreferences,
    useUserPreferences,
} from '@/composables/useUserPreferences';
import { formatDateTime, formatIsoDate } from '@/lib/formatDate';
import type { RecordPanel } from '@/lib/recordPanels';
import { buildRecordPanels, RELATIONS_PANEL_ID } from '@/lib/recordPanels';
import { recordRouteKey } from '@/lib/recordRouteKey';
import type { FieldGroupRow } from '@/types/fieldGroups';
import type { FieldDefinition } from '@/types/fields';
import type { UndoableMerge } from '@/types/merge';
import type { ObjectTypePreferences } from '@/types/preferences';
import { emptyObjectTypePreferences } from '@/types/preferences';
import type { RecordObjectType, RecordPayload } from '@/types/records';

import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const PENDING_RECORD_HINT = t(
    'i18n.pages.records.form.reminders_and_history_become_available_after_the_first_save',
);

const page = usePage();

const props = withDefaults(
    defineProps<{
        mode: 'create' | 'edit';
        objectType: RecordObjectType;
        fieldDefinitions: FieldDefinition[];
        fieldGroups?: FieldGroupRow[];
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

const listHref = computed<string>(() =>
    RecordGridsController.index.url({ objectType: props.objectType.slug }),
);

const heading = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.records.form.create', {
              value1: props.objectType.name,
          })
        : (props.record?.title ??
          props.record?.recordNumber ??
          t('i18n.pages.records.form.edit', {
              value1: props.objectType.name,
          })),
);

const description = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.records.form.all_fields_for_the_new_entry_in', {
              value1: props.objectType.name,
          })
        : t('i18n.pages.records.form.edit_and_save_all_fields_of_this_record'),
);

usePageBreadcrumbs(() => [
    { title: props.objectType.name, href: listHref.value },
    { title: heading.value },
]);

const isEditable = computed<boolean>(() => props.canUpdate && !props.trashed);

const mergedIntoId = computed<string | null>(
    () => props.record?.mergedIntoRecordId ?? null,
);

const mergeNoticeDismissed = ref<boolean>(false);

const undoDeadline = computed<string | null>(
    () => props.undoableMerge?.undoableUntil ?? null,
);

const isUndoWindowOpen = computed<boolean>(
    () =>
        undoDeadline.value === null ||
        new Date(undoDeadline.value).getTime() > Date.now(),
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

const { hidden: hiddenPanels, isVisible } = useHiddenSections(
    props.mode === 'edit' ? props.objectType.id : null,
);

const panels = computed<RecordPanel[]>(() =>
    buildRecordPanels(
        props.fieldDefinitions,
        props.fieldGroups ?? [],
        props.hasRelationships,
    ),
);

const hasVisibleFields = computed<boolean>(() =>
    panels.value.some(
        (panel) => panel.id !== RELATIONS_PANEL_ID && isVisible(panel.id),
    ),
);

const canManagePanels = computed<boolean>(() =>
    useUserPreferences().allows('panelVisibility', 'records'),
);

const panelSettingsOpen = ref<boolean>(false);

const extensionAttributes = ref<Record<string, unknown>>({});
const extensionValidators = new Map<string, () => Record<string, string>>();
const registerValidator = (
    key: string,
    validator: () => Record<string, string>,
): (() => void) => {
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
    saveAssignment,
    closeConflict,
    adoptRecord,
} = useRecordForm({
    mode: props.mode,
    objectType: props.objectType,
    record: props.record,
    collaboratorIds: props.collaborators.map((option) => option.value),
    attributes: () => extensionAttributes.value,
    validateExtensions: () =>
        Object.assign(
            {},
            ...[...extensionValidators.values()].map((validate) => validate()),
        ),
    onSaved: (saved) => {
        markSaved();

        if (props.mode === 'create') {
            router.visit(
                RecordsController.edit.url({ record: recordRouteKey(saved) }),
            );

            return;
        }

        onDetailChanged();
    },
});

const {
    options: peopleOptions,
    loading: peopleLoading,
    load: loadPeople,
} = useUserOptions(
    props.record === null
        ? undefined
        : collaboratorCandidates.url({ record: props.record.id }),
);

onMounted(() => {
    if (props.mode === 'edit' && props.record !== null && !props.trashed) {
        void loadPeople();
    }
});

const collaboratorChoices = computed<SelectOption[]>(() =>
    peopleOptions.value.filter((option) => option.value !== ownerId.value),
);

const watcherChoices = computed<SelectOption[]>(() =>
    collaboratorChoices.value.filter(
        (option) => !collaboratorIds.value.includes(option.value),
    ),
);

function onAssignmentChanged(): void {
    void saveAssignment();
}

function onOwnerChanged(): void {
    collaboratorIds.value = collaboratorIds.value.filter(
        (id) => id !== ownerId.value,
    );

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
    const subject =
        number === null
            ? t('i18n.pages.records.form.the_record')
            : `„${number}“`;

    return t(
        'i18n.pages.records.form.will_be_deleted_and_can_be_restored_from_the',
        { value1: subject },
    );
});

function onDuplicate(): void {
    if (props.record !== null) {
        void duplicate(props.record);
    }
}

function onMerge(): void {
    if (props.record !== null) {
        router.visit(
            RecordMergeController.show.url({ record: props.record.id }),
        );
    }
}

function onDelete(): void {
    if (props.record !== null) {
        deletion.request(props.record);
    }
}

const purgeNotice = computed<string>(() =>
    props.purgeOn === null
        ? t(
              'i18n.pages.records.form.this_record_was_deleted_it_will_remain_in_the',
          )
        : t(
              'i18n.pages.records.form.this_record_has_been_deleted_permanent_deletion_on',
              { value1: formatIsoDate(props.purgeOn) },
          ),
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

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () =>
        props.mode === 'create'
            ? { ...values.value, ...extensionAttributes.value }
            : { ...values.value },
    backHref: () => listHref.value,
});

function reloadRecord(): void {
    closeConflict();
    router.reload();
}

const timeline =
    useTemplateRef<InstanceType<typeof RecordTimeline>>('timeline');

function onDetailChanged(): void {
    timeline.value?.reload();
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head :title="heading" />

        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                variant="small"
                :title="heading"
                :description="description"
            />

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
                        :disabled="
                            peopleLoading ||
                            !canForObjectType(
                                props.objectType.slug,
                                'watchers.manage',
                            )
                        "
                    />
                </template>

                <RecordActionsMenu
                    :trashed="props.trashed"
                    :can-delete="
                        canForObjectType(props.objectType.slug, 'delete')
                    "
                    :can-merge="
                        canForObjectType(props.objectType.slug, 'merge')
                    "
                    :can-manage-panels="canManagePanels"
                    @manage-panels="panelSettingsOpen = true"
                    @duplicate="onDuplicate"
                    @merge="onMerge"
                    @delete="onDelete"
                />
            </div>
        </div>

        <Alert
            v-if="mergedIntoId !== null"
            variant="destructive"
            data-record-merged
        >
            <AlertTitle>{{
                t('i18n.pages.records.form.this_record_was_merged')
            }}</AlertTitle>
            <AlertDescription>
                {{
                    t(
                        'i18n.pages.records.form.its_content_lives_on_in_the_target_record',
                    )
                }}
                <Link
                    class="underline"
                    :href="RecordsController.edit.url({ record: mergedIntoId })"
                >
                    {{ t('i18n.pages.records.form.go_to_target_record') }}
                </Link>
            </AlertDescription>
        </Alert>

        <Alert
            v-else-if="props.trashed"
            variant="destructive"
            class="border-destructive/50 bg-destructive/10"
            data-record-trashed
        >
            <AlertTitle>{{
                t('i18n.pages.records.form.this_record_was_deleted')
            }}</AlertTitle>
            <AlertDescription class="flex flex-col gap-1 pr-10">
                <span data-record-purge-notice>{{ purgeNotice }}</span>
                <span v-if="props.deletionReason" data-record-deletion-reason>
                    {{ t('i18n.pages.records.form.reason') }}
                    {{ props.deletionReason }}
                </span>
                <span>
                    {{
                        t(
                            'i18n.pages.records.form.actions_are_disabled_restore_the_record_from_the_trash',
                        )
                    }}
                </span>
            </AlertDescription>

            <IconActionButton
                v-if="props.canPurge"
                :icon="Trash2"
                :label="t('i18n.pages.records.form.delete_permanently')"
                variant="destructive"
                test-id="record-purge"
                class="absolute top-2 right-2"
                @click="purgeDialogOpen = true"
            />
        </Alert>

        <Alert v-else-if="!props.canUpdate" data-record-readonly>
            <AlertTitle>{{
                t('i18n.pages.records.form.read_only_access')
            }}</AlertTitle>
            <AlertDescription>
                {{
                    t(
                        'i18n.pages.records.form.you_do_not_have_permission_to_change_this_record',
                    )
                }}
            </AlertDescription>
        </Alert>

        <UiExtensionPoint
            name="records.form"
            :context="{
                ...page.props,
                ...$attrs,
                ...props,
                record: currentRecord,
                editable: isEditable,
                errors,
                attributes: extensionAttributes,
                registerValidator,
                adoptRecord,
                changed: onDetailChanged,
                conflict: () => {
                    conflictOpen = true;
                },
            }"
        />

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <div data-record-detail-main class="flex flex-col gap-6">
                <RecordRelations
                    v-if="
                        props.record !== null &&
                        props.hasRelationships &&
                        isVisible(RELATIONS_PANEL_ID)
                    "
                    :record-id="props.record.id"
                    :object-type-id="props.objectType.id"
                    :readonly="props.trashed || !props.canUpdate"
                />

                <fieldset :disabled="!isEditable">
                    <DynamicForm
                        v-model="values"
                        :fields="props.fieldDefinitions"
                        :groups="props.fieldGroups ?? []"
                        :errors="errors"
                        :object-type-id="props.objectType.id"
                        :hidden-sections="hiddenPanels"
                    />
                </fieldset>

                <FormActions
                    v-if="isEditable && hasVisibleFields"
                    type="button"
                    :dirty="isDirty"
                    :processing="saving"
                    @save="submit"
                    @cancel="requestLeave"
                />
            </div>

            <div
                data-record-detail-aside
                class="flex flex-col gap-6 lg:col-span-2"
            >
                <template v-if="props.record !== null">
                    <RecordDetailTabs
                        :key="props.record.id"
                        :record-id="props.record.id"
                        :readonly="props.trashed"
                        @changed="onDetailChanged"
                    />

                    <Alert v-if="showsMergeNotice" data-record-merge-undo>
                        <AlertTitle>{{
                            t(
                                'i18n.pages.records.form.this_record_has_absorbed_another_record',
                            )
                        }}</AlertTitle>
                        <AlertDescription
                            class="flex flex-wrap items-center gap-3"
                        >
                            <span>
                                {{
                                    t(
                                        'i18n.pages.records.form.the_merge_can_still_be_reverted_values_you_have',
                                    )
                                }}
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

                    <RecordTimeline
                        ref="timeline"
                        :record-id="props.record.id"
                        :fields="props.fieldDefinitions"
                    />
                </template>

                <p
                    v-else
                    data-record-detail-pending
                    class="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
                >
                    {{ PENDING_RECORD_HINT }}
                </p>
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
            :description="
                t(
                    'i18n.pages.records.form.the_record_will_be_permanently_deleted_and_cannot_be',
                )
            "
            :confirm-label="t('i18n.pages.records.form.delete_permanently')"
            variant="destructive"
            :pending="purging"
            @confirm="confirmPurge"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />

        <ConflictDialog
            :open="conflictOpen"
            @close="closeConflict"
            @reload="reloadRecord"
        />
    </div>
</template>
