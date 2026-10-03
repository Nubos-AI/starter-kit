<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCcw, Trash2 } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ApiTokensController from '@/actions/App/Http/Controllers/Api/ApiTokensController';
import ApiTokenSecretDialog from '@/components/api/ApiTokenSecretDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import ViewStates from '@/components/engine/ViewStates.vue';
import Heading from '@/components/Heading.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { formatAbility } from '@/lib/apiTokenAccess';
import { formatDateTime } from '@/lib/formatDate';

const { t } = useI18n();

interface ApiTokenRow {
    id: string;
    name: string;
    abilities: string[];
    ownerName: string | null;
    ownerEmail: string | null;
    isService: boolean;
    lastUsedAt: string | null;
    expiresAt: string | null;
    createdAt: string | null;
}

const props = withDefaults(
    defineProps<{
        tokens?: ApiTokenRow[];
        secret?: string | null;
    }>(),
    {
        tokens: () => [],
        secret: null,
    },
);

const tokens = computed<ApiTokenRow[]>(() => props.tokens ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    tokens.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(tokens, (rows) => selection.prune(rows.map((row) => row.id)));

function bulkRevokeTokens(): void {
    router.post(
        ApiTokensController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

const pendingRotate = ref<ApiTokenRow | null>(null);
const pendingRevoke = ref<ApiTokenRow | null>(null);
const actionPending = ref<boolean>(false);

const rotateDialogOpen = computed<boolean>({
    get: () => pendingRotate.value !== null,
    set: (value) => {
        if (!value) {
            pendingRotate.value = null;
        }
    },
});

const revokeDialogOpen = computed<boolean>({
    get: () => pendingRevoke.value !== null,
    set: (value) => {
        if (!value) {
            pendingRevoke.value = null;
        }
    },
});

const rotateDescription = computed<string>(() =>
    pendingRotate.value === null
        ? ''
        : t(
              'i18n.pages.api_tokens.index.a_new_secret_will_be_generated_for_and_shown',
              { value1: pendingRotate.value.name },
          ),
);

const revokeDescription = computed<string>(() =>
    pendingRevoke.value === null
        ? ''
        : t(
              'i18n.pages.api_tokens.index.will_stop_working_immediately_every_integration_using_it_will',
              { value1: pendingRevoke.value.name },
          ),
);

function goToCreate(): void {
    router.visit(ApiTokensController.create.url());
}

function confirmRotate(): void {
    const row = pendingRotate.value;

    if (row === null) {
        return;
    }

    actionPending.value = true;

    router.post(
        ApiTokensController.rotate.url({ token: row.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                actionPending.value = false;
                pendingRotate.value = null;
            },
        },
    );
}

function confirmRevoke(): void {
    const row = pendingRevoke.value;

    if (row === null) {
        return;
    }

    actionPending.value = true;

    router.delete(ApiTokensController.destroy.url({ token: row.id }), {
        preserveScroll: true,
        onFinish: () => {
            actionPending.value = false;
            pendingRevoke.value = null;
        },
    });
}

const columnDefs = computed<ColDef<ApiTokenRow>[]>(() => [
    selectionColumn<ApiTokenRow>(selection),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.api_tokens.index.name'),
        flex: 2,
    },
    {
        colId: 'owner',
        headerName: t('i18n.pages.api_tokens.index.owner'),
        flex: 2,
        valueGetter: (params) =>
            params.data?.ownerName || params.data?.ownerEmail || '—',
    },
    {
        colId: 'origin',
        headerName: t('i18n.pages.api_tokens.index.origin'),
        flex: 1,
        valueGetter: (params) =>
            params.data?.isService === true
                ? t('i18n.pages.api_tokens.index.service')
                : t('i18n.pages.api_tokens.index.personal'),
    },
    {
        colId: 'abilities',
        headerName: t('i18n.pages.api_tokens.index.permissions'),
        flex: 2,
        sortable: false,
        valueGetter: (params) =>
            (params.data?.abilities ?? [])
                .map((ability) => formatAbility(ability))
                .join(', '),
    },
    {
        colId: 'lastUsedAt',
        field: 'lastUsedAt',
        headerName: t('i18n.pages.api_tokens.index.last_used'),
        flex: 1,
        valueFormatter: (params) => formatDateTime(params.value ?? null),
    },
    {
        colId: 'expiresAt',
        field: 'expiresAt',
        headerName: t('i18n.pages.api_tokens.index.expires'),
        flex: 1,
        valueFormatter: (params) => formatDateTime(params.value ?? null),
    },
    actionsColumn<ApiTokenRow>([
        {
            icon: RotateCcw,
            label: t('i18n.pages.api_tokens.index.rotate'),
            testId: 'rotate-api-token',
            isVisible: (row) => row.isService,
            onClick: (row) => (pendingRotate.value = row),
        },
        {
            icon: Trash2,
            label: t('i18n.pages.api_tokens.index.revoke'),
            variant: 'destructive',
            testId: 'revoke-api-token',
            onClick: (row) => (pendingRevoke.value = row),
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.api_tokens.index.api_tokens')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.api_tokens.index.api_tokens')"
                :description="
                    t(
                        'i18n.pages.api_tokens.index.all_tokens_for_this_tenant_service_tokens_act_as',
                    )
                "
            />
            <CreateButton
                :href="ApiTokensController.create.url()"
                :label="t('i18n.pages.api_tokens.index.create_new')"
            />
        </div>

        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Token widerrufen"
            @delete="bulkRevokeTokens"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                empty-kind="no-records"
                skeleton-variant="list"
                empty-title="Noch keine API-Tokens"
                empty-description="Legen Sie ein Token an, um ein externes System an die REST-API anzubinden."
                create-label="Neu anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="tokens"
                    :aria-label="t('i18n.pages.api_tokens.index.api_tokens')"
                />
            </div>
        </div>

        <ApiTokenSecretDialog :secret="props.secret" />

        <ConfirmDialog
            v-model:open="rotateDialogOpen"
            :title="t('i18n.pages.api_tokens.index.rotate_token')"
            :description="rotateDescription"
            :confirm-label="t('i18n.pages.api_tokens.index.rotate')"
            :pending="actionPending"
            @confirm="confirmRotate"
        />

        <ConfirmDialog
            v-model:open="revokeDialogOpen"
            :title="t('i18n.pages.api_tokens.index.revoke_token')"
            :description="revokeDescription"
            :confirm-label="t('i18n.pages.api_tokens.index.revoke')"
            variant="destructive"
            :pending="actionPending"
            @confirm="confirmRevoke"
        />
    </ListPage>
</template>
