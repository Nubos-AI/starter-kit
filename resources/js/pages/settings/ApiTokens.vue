<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCcw, Trash2 } from '@lucide/vue';
import type { ColDef, ValueFormatterParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ApiTokensController from '@/actions/App/Http/Controllers/Settings/ApiTokensController';
import ApiTokenSecretDialog from '@/components/api/ApiTokenSecretDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import ViewStates from '@/components/engine/ViewStates.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { useRowDeletion } from '@/composables/useRowDeletion';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime } from '@/lib/formatDate';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

interface PersonalApiTokenRow {
    id: string;
    name: string;
    abilities: string[];
    lastUsedAt: string | null;
    expiresAt: string | null;
    createdAt: string | null;
}

const props = withDefaults(
    defineProps<{
        tokens?: PersonalApiTokenRow[];
        abilityOptions?: SelectOption[];
        secret?: string | null;
    }>(),
    {
        tokens: () => [],
        abilityOptions: () => [],
        secret: null,
    },
);

const name = ref<string>('');
const abilities = ref<string[]>([]);
const expiresAt = ref<string>('');
const creating = ref<boolean>(false);
const errors = ref<Record<string, string>>({});

const pendingRotate = ref<PersonalApiTokenRow | null>(null);
const rotatePending = ref<boolean>(false);

const {
    pendingDelete: pendingRevoke,
    deletePending: revokePending,
    deleteDialogOpen: revokeDialogOpen,
    confirmDelete: confirmRevoke,
} = useRowDeletion<PersonalApiTokenRow>({
    url: (row) => ApiTokensController.destroy.url({ token: row.id }),
});

const selection = useListSelection();

watch(
    () => props.tokens,
    (rows) => selection.prune(rows.map((row) => row.id)),
);

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

const abilityLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.abilityOptions.map((option) => [option.value, option.label]),
    ),
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
        name: name.value,
        abilities: [...abilities.value].sort(),
        expiresAt: expiresAt.value,
    }),
    backHref: ApiTokensController.index.url(),
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
              'i18n.pages.settings.api_tokens.a_new_secret_will_be_generated_for_and_shown',
              { value1: pendingRotate.value.name },
          ),
);

const revokeDescription = computed<string>(() =>
    pendingRevoke.value === null
        ? ''
        : t('i18n.pages.settings.api_tokens.will_stop_working_immediately', {
              value1: pendingRevoke.value.name,
          }),
);

function abilityLabel(ability: string): string {
    return abilityLabels.value[ability] ?? ability;
}

function createToken(): void {
    creating.value = true;
    errors.value = {};

    router.post(
        ApiTokensController.store.url(),
        {
            name: name.value,
            abilities: abilities.value,
            expiresAt: expiresAt.value === '' ? null : expiresAt.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                name.value = '';
                abilities.value = [];
                expiresAt.value = '';
                markSaved();
            },
            onError: (received) => {
                errors.value = received;
            },
            onFinish: () => {
                creating.value = false;
            },
        },
    );
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

const columnDefs = computed<ColDef<PersonalApiTokenRow>[]>(() => [
    selectionColumn<PersonalApiTokenRow>(selection),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.settings.api_tokens.name'),
        flex: 1,
        minWidth: 160,
    },
    {
        colId: 'abilities',
        field: 'abilities',
        headerName: t('i18n.pages.settings.api_tokens.permissions'),
        flex: 2,
        minWidth: 200,
        valueFormatter: (
            params: ValueFormatterParams<PersonalApiTokenRow>,
        ): string =>
            params.data === undefined
                ? ''
                : params.data.abilities.map(abilityLabel).join(', '),
    },
    {
        colId: 'lastUsedAt',
        field: 'lastUsedAt',
        headerName: t('i18n.pages.settings.api_tokens.last_used_at'),
        width: 170,
        minWidth: 140,
        valueFormatter: (
            params: ValueFormatterParams<PersonalApiTokenRow>,
        ): string =>
            params.data === undefined
                ? ''
                : formatDateTime(params.data.lastUsedAt),
    },
    {
        colId: 'expiresAt',
        field: 'expiresAt',
        headerName: t('i18n.pages.settings.api_tokens.expires_at'),
        width: 170,
        minWidth: 140,
        valueFormatter: (
            params: ValueFormatterParams<PersonalApiTokenRow>,
        ): string =>
            params.data === undefined
                ? ''
                : formatDateTime(params.data.expiresAt),
    },
    actionsColumn<PersonalApiTokenRow>([
        {
            icon: RotateCcw,
            label: t('i18n.pages.settings.api_tokens.rotate'),
            testId: 'token-rotate',
            onClick: (row) => (pendingRotate.value = row),
        },
        {
            icon: Trash2,
            label: t('i18n.pages.settings.api_tokens.revoke'),
            variant: 'destructive',
            testId: 'token-revoke',
            onClick: (row) => (pendingRevoke.value = row),
        },
    ]),
]);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('i18n.pages.settings.api_tokens.api_token')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.settings.api_tokens.api_token')"
            :description="
                t(
                    'i18n.pages.settings.api_tokens.personal_tokens_for_the_rest_api_a_token_has',
                )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.api_tokens.new_token')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.api_tokens.the_secret_is_shown_only_once_after_creation',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="token_name">{{
                            t('i18n.pages.settings.api_tokens.name')
                        }}</Label>
                        <Input
                            id="token_name"
                            v-model="name"
                            :placeholder="
                                t(
                                    'i18n.pages.settings.api_tokens.e_g_laptop_cli',
                                )
                            "
                            data-testid="token-name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token_expires">{{
                            t('i18n.pages.settings.api_tokens.expires_optional')
                        }}</Label>
                        <Input
                            id="token_expires"
                            v-model="expiresAt"
                            type="datetime-local"
                            data-testid="token-expires"
                        />
                        <InputError :message="errors.expiresAt" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="token_abilities">{{
                        t('i18n.pages.settings.api_tokens.permissions')
                    }}</Label>
                    <MultiSelect
                        id="token_abilities"
                        v-model="abilities"
                        :options="props.abilityOptions"
                        :placeholder="
                            t(
                                'i18n.pages.settings.api_tokens.select_permissions',
                            )
                        "
                        data-testid="token-abilities"
                    />
                    <InputError :message="errors.abilities" />
                </div>

                <FormActions
                    type="button"
                    :dirty="isDirty"
                    :processing="creating"
                    @save="createToken"
                    @cancel="requestLeave"
                />
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.api_tokens.my_tokens')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.api_tokens.a_rotated_token_keeps_its_name_and_permissions_but',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div
                    v-if="props.tokens.length === 0"
                    class="relative min-h-40"
                    data-testid="token-empty"
                >
                    <ViewStates
                        state="empty"
                        :empty-title="
                            t('i18n.pages.settings.api_tokens.no_tokens_yet')
                        "
                        :empty-description="
                            t(
                                'i18n.pages.settings.api_tokens.you_have_not_created_a_personal_token_yet',
                            )
                        "
                    />
                </div>

                <template v-else>
                    <SelectionBulkBar
                        :count="selection.count.value"
                        @delete="bulkRevokeTokens"
                        @clear="selection.clear"
                    />

                    <div class="h-80" data-testid="token-list">
                        <DataGrid
                            class="h-full w-full"
                            :column-defs="columnDefs"
                            :row-data="props.tokens"
                            :aria-label="
                                t('i18n.pages.settings.api_tokens.my_tokens')
                            "
                        />
                    </div>
                </template>
            </CardContent>
        </Card>

        <ApiTokenSecretDialog :secret="props.secret" />

        <ConfirmDialog
            v-model:open="rotateDialogOpen"
            :title="t('i18n.pages.settings.api_tokens.rotate_token')"
            :description="rotateDescription"
            :confirm-label="t('i18n.pages.settings.api_tokens.rotate')"
            :pending="rotatePending"
            @confirm="confirmRotate"
        />

        <ConfirmDialog
            v-model:open="revokeDialogOpen"
            :title="t('i18n.pages.settings.api_tokens.revoke_token')"
            :description="revokeDescription"
            :confirm-label="t('i18n.pages.settings.api_tokens.revoke')"
            variant="destructive"
            :pending="revokePending"
            @confirm="confirmRevoke"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
