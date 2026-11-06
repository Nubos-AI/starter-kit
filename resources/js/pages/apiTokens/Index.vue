<script setup lang="ts">
import { router } from '@inertiajs/vue3';
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
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { useRowDeletion } from '@/composables/useRowDeletion';
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
const rotatePending = ref<boolean>(false);

const {
    pendingDelete: pendingRevoke,
    deletePending: revokePending,
    deleteDialogOpen: revokeDialogOpen,
    confirmDelete: confirmRevoke,
} = useRowDeletion<ApiTokenRow>({
    url: (row) => ApiTokensController.destroy.url({ token: row.id }),
});

const rotateDialogOpen = computed<boolean>({
    get: () => pendingRotate.value !== null,
    set: (value) => {
        if (!value) {
            pendingRotate.value = null;
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

    rotatePending.value = true;

    router.post(
        ApiTokensController.rotate.url({ token: row.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                rotatePending.value = false;
                pendingRotate.value = null;
            },
        },
    );
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
    <ListPage
        :title="t('i18n.pages.api_tokens.index.api_tokens')"
        :description="
            t(
                'i18n.pages.api_tokens.index.all_tokens_for_this_tenant_service_tokens_act_as',
            )
        "
    >
        <template #actions>
            <CreateButton
                :href="ApiTokensController.create.url()"
                :label="t('i18n.pages.api_tokens.index.create_new')"
            />
        </template>

        <SelectionBulkBar
            :count="selection.count.value"
            :delete-label="t('i18n.pages.api_tokens.index.revoke_tokens')"
            @delete="bulkRevokeTokens"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                empty-kind="no-records"
                skeleton-variant="list"
                :empty-title="
                    t('i18n.pages.api_tokens.index.no_api_tokens_yet')
                "
                :empty-description="
                    t(
                        'i18n.pages.api_tokens.index.create_a_token_to_connect_an_external_system',
                    )
                "
                :create-label="t('i18n.pages.api_tokens.index.create_new')"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 flex-1">
                <DataGrid
                    class="h-full"
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
            :pending="rotatePending"
            @confirm="confirmRotate"
        />

        <ConfirmDialog
            v-model:open="revokeDialogOpen"
            :title="t('i18n.pages.api_tokens.index.revoke_token')"
            :description="revokeDescription"
            :confirm-label="t('i18n.pages.api_tokens.index.revoke')"
            variant="destructive"
            :pending="revokePending"
            @confirm="confirmRevoke"
        />
    </ListPage>
</template>
