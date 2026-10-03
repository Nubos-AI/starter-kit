<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueFormatterParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import AbsencesController from '@/actions/App/Http/Controllers/Settings/AbsencesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatIsoDate } from '@/lib/formatDate';
import type { SelectOption } from '@/types/ui';

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

function isErrorBag(value: unknown): value is Record<string, string> {
    return typeof value === 'object' && value !== null;
}

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

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
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

function withSelections(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    return {
        ...data,
        delegateId: delegateId.value,
        userId: props.subject.id,
    };
}

const selection = useListSelection();

watch(delegations, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const pendingDelete = ref<DelegationRow | null>(null);
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

function confirmDelete(): void {
    const row = pendingDelete.value;

    if (row === null) {
        return;
    }

    deletePending.value = true;

    router.delete(AbsencesController.destroy.url({ absence: row.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletePending.value = false;
            pendingDelete.value = null;

            if (editingId.value === row.id) {
                resetForm();
            }
        },
    });
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
        valueFormatter: (
            params: ValueFormatterParams<DelegationRow>,
        ): string =>
            params.data === undefined
                ? ''
                : formatIsoDate(params.data.startsAt),
    },
    {
        colId: 'endsAt',
        field: 'endsAt',
        headerName: t('i18n.pages.settings.absences.to'),
        width: 140,
        minWidth: 120,
        valueFormatter: (
            params: ValueFormatterParams<DelegationRow>,
        ): string =>
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
            :description="
                t(
                    'i18n.pages.settings.absences.choose_who_covers_for_you_during_an_absence_approvals',
                )
            "
        />

        <Card v-if="canManage">
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.absences.manage_absence_for')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.absences.select_a_person_to_manage_their_absence_periods',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="absence-subject">{{
                        t('i18n.pages.settings.absences.person')
                    }}</Label>
                    <Combobox
                        id="absence-subject"
                        v-model="subjectId"
                        :options="props.manageableUserOptions"
                        :search-placeholder="
                            t('i18n.pages.settings.absences.search_people')
                        "
                        :aria-label="
                            t('i18n.pages.settings.absences.manage_absence_for')
                        "
                    />
                    <InputError :message="subjectError" />
                </div>
            </CardContent>
        </Card>

        <Alert v-if="!isOwnSubject" data-testid="absence-audit-hint">
            <AlertDescription>
                {{
                    t(
                        'i18n.pages.settings.absences.you_are_managing_the_absence_of',
                    )
                }}
                {{ props.subject.name
                }}{{
                    t(
                        'i18n.pages.settings.absences.every_change_is_logged_under_your_name',
                    )
                }}
            </AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.absences.deputy')
                }}</CardTitle>
                <CardDescription>
                    {{
                        editingId === null
                            ? t(
                                  'i18n.pages.settings.absences.enter_a_new_absence_period',
                              )
                            : t(
                                  'i18n.pages.settings.absences.edit_the_selected_absence_period',
                              )
                    }}
                </CardDescription>
                <CardAction v-if="editingId !== null">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid="absence-new"
                        @click="resetForm"
                    >
                        {{ t('i18n.pages.settings.absences.new_period') }}
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="formAction"
                    :transform="withSelections"
                    :on-success="resetForm"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="absence-delegate">{{
                                t('i18n.pages.settings.absences.deputy')
                            }}</Label>
                            <Combobox
                                id="absence-delegate"
                                v-model="delegateId"
                                :options="props.delegateOptions"
                                :search-placeholder="
                                    t(
                                        'i18n.pages.settings.absences.search_people',
                                    )
                                "
                                :empty-label="
                                    t(
                                        'i18n.pages.settings.absences.there_are_no_other_people',
                                    )
                                "
                                :aria-label="
                                    t('i18n.pages.settings.absences.deputy')
                                "
                            />
                            <InputError :message="errors.delegateId" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="absence-starts-at">{{
                                t('i18n.pages.settings.absences.from')
                            }}</Label>
                            <Input
                                id="absence-starts-at"
                                v-model="startsAt"
                                name="startsAt"
                                type="date"
                                required
                            />
                            <InputError :message="errors.startsAt" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="absence-ends-at">{{
                                t('i18n.pages.settings.absences.to')
                            }}</Label>
                            <Input
                                id="absence-ends-at"
                                v-model="endsAt"
                                name="endsAt"
                                type="date"
                                required
                            />
                            <InputError :message="errors.endsAt" />
                        </div>
                    </div>

                    <FormActions
                        :dirty="isDirty"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </Form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.absences.saved_periods')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.absences.periods_must_not_overlap_consecutive_periods_are_allowed',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <SelectionBulkBar
                    :count="selection.count.value"
                    delete-label="Zeiträume löschen"
                    @delete="bulkDeleteDelegations"
                    @clear="selection.clear"
                />

                <p
                    v-if="delegations.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'i18n.pages.settings.absences.no_absence_period_has_been_saved_yet',
                        )
                    }}
                </p>

                <div v-else class="h-80 w-full">
                    <DataGrid
                        class="h-full w-full"
                        :column-defs="columnDefs"
                        :row-data="delegations"
                        :is-row-activatable="
                            (row: DelegationRow) => row.can_update
                        "
                        :aria-label="
                            t('i18n.pages.settings.absences.absence_periods')
                        "
                        @row-activate="loadRow"
                    />
                </div>
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

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="abortSubjectSwitch"
        />
    </div>
</template>
