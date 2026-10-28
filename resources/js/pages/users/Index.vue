<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Ban, CircleCheck, Pencil, Send, Trash2 } from '@lucide/vue';
import type { ColDef, ValueGetterParams } from 'ag-grid-community';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import UserInvitationsController from '@/actions/App/Http/Controllers/Users/UserInvitationsController';
import UsersController from '@/actions/App/Http/Controllers/Users/UsersController';
import UserStatusesController from '@/actions/App/Http/Controllers/Users/UserStatusesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import { StatusBadgeCellRenderer } from '@/components/data-grid/StatusBadgeCellRenderer';
import ViewStates from '@/components/engine/ViewStates.vue';
import Heading from '@/components/Heading.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import type { StatusMap } from '@/lib/statusMaps';
import { USER_STATUS } from '@/lib/statusMaps';
import type { AssignableRole, UserRow } from '@/types/users';

const { t } = useI18n();

const props = defineProps<{
    management?: { inviteUrl: string | null };
    users: UserRow[];
    roles: AssignableRole[];
}>();

const { can } = usePermissions();

const AUTHORITY_STATUS: StatusMap = {
    escalated: {
        label: t('i18n.pages.users.index.elevated'),
        variant: 'secondary',
    },
    standard: {
        label: t('i18n.pages.users.index.default'),
        variant: 'outline',
    },
};

const roleNames = computed<Record<string, string>>(() =>
    Object.fromEntries(props.roles.map((role) => [role.id, role.name])),
);

const listState = computed<'empty' | 'ready'>(() =>
    props.users.length === 0 ? 'empty' : 'ready',
);

const pendingDelete = ref<UserRow | null>(null);
const deletePending = ref<boolean>(false);
const pendingBlock = ref<UserRow | null>(null);
const blockPending = ref<boolean>(false);

const confirmOpen = computed<boolean>({
    get: () => pendingDelete.value !== null,
    set: (value) => {
        if (!value) {
            pendingDelete.value = null;
        }
    },
});

const blockOpen = computed<boolean>({
    get: () => pendingBlock.value !== null,
    set: (value) => {
        if (!value) {
            pendingBlock.value = null;
        }
    },
});

const confirmDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.users.index.will_lose_access_assignments_will_be_retained_and_can',
              { value1: displayName(pendingDelete.value) },
          ),
);

const blockDescription = computed<string>(() =>
    pendingBlock.value === null
        ? ''
        : pendingBlock.value.status === 'blocked'
          ? t('i18n.pages.users.index.will_be_able_to_sign_in_again', {
                value1: displayName(pendingBlock.value),
            })
          : t(
                'i18n.pages.users.index.will_be_signed_out_everywhere_and_can_no_longer',
                { value1: displayName(pendingBlock.value) },
            ),
);

const blockTitle = computed<string>(() =>
    pendingBlock.value?.status === 'blocked'
        ? t('i18n.pages.users.index.unblock_user')
        : t('i18n.pages.users.index.block_user'),
);

function displayName(row: UserRow): string {
    return row.name === '' ? row.email : row.name;
}

function roleLabels(user: UserRow): string[] {
    return user.role_ids
        .map((id) => roleNames.value[id])
        .filter((name): name is string => name !== undefined);
}

function goToEdit(row: UserRow): void {
    router.visit(UsersController.edit.url({ user: row.id }));
}

function requestDelete(row: UserRow): void {
    pendingDelete.value = row;
}

function requestBlock(row: UserRow): void {
    pendingBlock.value = row;
}

function resendInvitation(row: UserRow): void {
    router.post(
        UserInvitationsController.resend.url({ user: row.id }),
        {},
        { preserveScroll: true },
    );
}

function confirmDelete(): void {
    const row = pendingDelete.value;

    if (row === null) {
        return;
    }

    deletePending.value = true;

    router.delete(UsersController.destroy.url({ user: row.id }), {
        preserveScroll: true,
        onError: (errors) => {
            toast.error(
                errors.user ??
                    t(
                        'i18n.pages.users.index.the_user_could_not_be_deleted_please_try_again',
                    ),
            );
        },
        onFinish: () => {
            deletePending.value = false;
            pendingDelete.value = null;
        },
    });
}

function confirmBlock(): void {
    const row = pendingBlock.value;

    if (row === null) {
        return;
    }

    blockPending.value = true;

    router.put(
        row.status_url ?? UserStatusesController.url({ user: row.id }),
        { status: row.status === 'blocked' ? 'accepted' : 'blocked' },
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(
                    errors.status ??
                        t(
                            'i18n.pages.users.index.the_status_could_not_be_changed_please_try_again',
                        ),
                );
            },
            onFinish: () => {
                blockPending.value = false;
                pendingBlock.value = null;
            },
        },
    );
}

const columnDefs = computed<ColDef<UserRow>[]>(() => [
    {
        colId: 'name',
        headerName: t('i18n.pages.users.index.name'),
        flex: 2,
        valueGetter: (params: ValueGetterParams<UserRow>): string =>
            params.data === undefined ? '' : displayName(params.data),
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: UserRow): string | undefined =>
                row.can_update
                    ? UsersController.edit.url({ user: row.id })
                    : undefined,
        },
    },
    {
        colId: 'email',
        field: 'email',
        headerName: t('i18n.pages.users.index.email'),
        flex: 2,
    },
    {
        colId: 'status',
        field: 'status',
        headerName: t('i18n.pages.users.index.status'),
        width: 140,
        cellRenderer: StatusBadgeCellRenderer,
        cellRendererParams: { statusMap: USER_STATUS },
    },
    {
        colId: 'roles',
        hide: props.management !== undefined,
        headerName: t('i18n.pages.users.index.roles'),
        flex: 3,
        sortable: false,
        valueGetter: (params: ValueGetterParams<UserRow>): string =>
            params.data === undefined || roleLabels(params.data).length === 0
                ? '—'
                : roleLabels(params.data).join(', '),
    },
    {
        colId: 'authority',
        hide: props.management !== undefined,
        headerName: t('i18n.pages.users.index.authority'),
        width: 160,
        valueGetter: (params: ValueGetterParams<UserRow>): string =>
            params.data?.is_escalated === true ? 'escalated' : 'standard',
        cellRenderer: StatusBadgeCellRenderer,
        cellRendererParams: { statusMap: AUTHORITY_STATUS },
    },
    actionsColumn<UserRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.users.index.edit'),
            variant: 'edit',
            onClick: (row) => goToEdit(row),
            isVisible: (row) => row.can_update,
        },
        {
            icon: Send,
            label: t('i18n.pages.users.index.resend_invitation'),
            onClick: (row) => resendInvitation(row),
            isVisible: (row) => row.can_resend_invitation,
        },
        {
            icon: CircleCheck,
            label: t('i18n.pages.users.index.unblock'),
            onClick: (row) => requestBlock(row),
            isVisible: (row) => row.status === 'blocked',
            isDisabled: (row) => !row.can_block,
            disabledReason: (row) => row.block_reason ?? undefined,
        },
        {
            icon: Ban,
            label: t('i18n.pages.users.index.block'),
            onClick: (row) => requestBlock(row),
            isVisible: (row) =>
                row.status !== 'blocked' &&
                (row.can_block || row.block_reason !== null),
            isDisabled: (row) => !row.can_block,
            disabledReason: (row) => row.block_reason ?? undefined,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.users.index.delete'),
            variant: 'destructive',
            onClick: (row) => requestDelete(row),
            isVisible: (row) => row.can_delete || row.delete_reason !== null,
            isDisabled: (row) => !row.can_delete,
            disabledReason: (row) => row.delete_reason ?? undefined,
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.users.index.users')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.users.index.users')"
                :description="
                    management
                        ? t(
                              'i18n.pages.users.index.manage_customers_users_and_access',
                          )
                        : t(
                              'i18n.pages.users.index.who_holds_which_role_and_therefore_which_permissions_apply',
                          )
                "
            />

            <CreateButton
                v-if="management ? management.inviteUrl : can('members.invite')"
                :label="t('i18n.pages.users.index.invite')"
                :href="
                    management?.inviteUrl ??
                    UserInvitationsController.create.url()
                "
            />
        </div>

        <UiExtensionPoint name="users.index.filters" />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                empty-kind="empty-column"
                skeleton-variant="list"
                empty-title="Keine Benutzer"
                empty-description="Es gibt noch keine Benutzer."
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    preference-key="users"
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="users"
                    :is-row-activatable="(row: UserRow) => row.can_update"
                    :aria-label="t('i18n.pages.users.index.users')"
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('i18n.pages.users.index.delete_user')"
            :description="confirmDescription"
            :confirm-label="t('i18n.pages.users.index.delete')"
            :cancel-label="t('i18n.pages.users.index.cancel')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />

        <ConfirmDialog
            v-model:open="blockOpen"
            :title="blockTitle"
            :description="blockDescription"
            :confirm-label="
                pendingBlock?.status === 'blocked'
                    ? t('i18n.pages.users.index.unblock')
                    : t('i18n.pages.users.index.block')
            "
            :cancel-label="t('i18n.pages.users.index.cancel')"
            :variant="
                pendingBlock?.status === 'blocked' ? 'default' : 'destructive'
            "
            :pending="blockPending"
            @confirm="confirmBlock"
        />
    </ListPage>
</template>
