<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueGetterParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import WebhookSubscriptionsController from '@/actions/App/Http/Controllers/Webhooks/WebhookSubscriptionsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import { statusBadgeColumn } from '@/components/data-grid/StatusBadgeCellRenderer';
import ViewStates from '@/components/engine/ViewStates.vue';
import Heading from '@/components/Heading.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { WEBHOOK_STATUS } from '@/lib/statusMaps';

const { t } = useI18n();

interface WebhookSubscriptionRow {
    id: string;
    name: string;
    targetUrl: string;
    status: string;
    eventTypes: string[];
    objectType: string | null;
    consecutiveFailures: number;
    lastError: string | null;
    activatedAt: string | null;
    rotatedAt: string | null;
    createdAt: string | null;
}

const props = withDefaults(
    defineProps<{
        subscriptions?: WebhookSubscriptionRow[];
    }>(),
    { subscriptions: () => [] },
);

const ALL_OBJECT_TYPES_LABEL = t('i18n.pages.webhooks.index.all_object_types');

const subscriptions = computed<WebhookSubscriptionRow[]>(
    () => props.subscriptions ?? [],
);

const listState = computed<'empty' | 'ready'>(() =>
    subscriptions.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(subscriptions, (rows) => selection.prune(rows.map((row) => row.id)));

function bulkDeleteWebhooks(): void {
    router.post(
        WebhookSubscriptionsController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

const pendingDelete = ref<WebhookSubscriptionRow | null>(null);
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
              'i18n.pages.webhooks.index.will_no_longer_receive_events_this_action_cannot_be',
              { value1: pendingDelete.value.name },
          ),
);

function goToEdit(row: WebhookSubscriptionRow): void {
    router.visit(
        WebhookSubscriptionsController.edit.url({ subscription: row.id }),
    );
}

function goToCreate(): void {
    router.visit(WebhookSubscriptionsController.create.url());
}

function confirmDelete(): void {
    const row = pendingDelete.value;

    if (row === null) {
        return;
    }

    deletePending.value = true;

    router.delete(
        WebhookSubscriptionsController.destroy.url({ subscription: row.id }),
        {
            preserveScroll: true,
            onFinish: () => {
                deletePending.value = false;
                pendingDelete.value = null;
            },
        },
    );
}

const columnDefs = computed<ColDef<WebhookSubscriptionRow>[]>(() => [
    selectionColumn<WebhookSubscriptionRow>(selection),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.webhooks.index.name'),
        flex: 1,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: WebhookSubscriptionRow): string =>
                WebhookSubscriptionsController.edit.url({
                    subscription: row.id,
                }),
        },
    },
    statusBadgeColumn<WebhookSubscriptionRow>('status', WEBHOOK_STATUS, {
        headerName: t('i18n.pages.webhooks.index.status'),
        width: 150,
    }),
    {
        colId: 'objectType',
        headerName: t('i18n.pages.webhooks.index.object_type'),
        flex: 1,
        valueGetter: (
            params: ValueGetterParams<WebhookSubscriptionRow>,
        ): string => params.data?.objectType ?? ALL_OBJECT_TYPES_LABEL,
    },
    {
        colId: 'consecutiveFailures',
        field: 'consecutiveFailures',
        headerName: t('i18n.pages.webhooks.index.consecutive_failures'),
        width: 150,
    },
    {
        colId: 'lastError',
        field: 'lastError',
        headerName: t('i18n.pages.webhooks.index.last_error'),
        flex: 2,
        type: 'danger',
        valueFormatter: (params) => params.value ?? '',
    },
    actionsColumn<WebhookSubscriptionRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.webhooks.index.edit'),
            href: (row) =>
                WebhookSubscriptionsController.edit.url({
                    subscription: row.id,
                }),
        },
        {
            icon: Trash2,
            label: t('i18n.pages.webhooks.index.delete'),
            variant: 'destructive',
            onClick: (row) => (pendingDelete.value = row),
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.webhooks.index.webhooks')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.webhooks.index.webhooks')"
                :description="
                    t(
                        'i18n.pages.webhooks.index.notify_external_systems_about_events_each_subscription_delivers_signed',
                    )
                "
            />
            <CreateButton
                :href="WebhookSubscriptionsController.create.url()"
                :label="t('i18n.pages.webhooks.index.create_new')"
            />
        </div>

        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Webhooks löschen"
            @delete="bulkDeleteWebhooks"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                empty-kind="no-records"
                skeleton-variant="list"
                empty-title="Noch keine Webhooks"
                empty-description="Legen Sie einen Webhook an, um ein externes System über Ereignisse zu informieren."
                create-label="Neu anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="subscriptions"
                    :is-row-activatable="() => true"
                    :aria-label="t('i18n.pages.webhooks.index.webhooks')"
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="t('i18n.pages.webhooks.index.delete_webhook')"
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.webhooks.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
