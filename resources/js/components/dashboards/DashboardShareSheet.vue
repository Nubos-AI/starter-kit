<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import { IconActionButton } from '@/components/ui/icon-action-button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useDashboardShares } from '@/composables/useDashboardShares';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import type {
    DashboardRow,
    DashboardShare,
    ShareGranteeType,
} from '@/types/dashboards';
import {
    DEFINER_SHARE_WARNING,
    SHARE_GRANTEE_LABELS,
    SHARE_UNKNOWN_GRANTEE,
    TENANT_WIDE_HINT,
    TENANT_WIDE_LABEL,
} from '@/types/dashboards';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const SEARCHABLE_THRESHOLD = 8;

const SHEET_TITLE = t(
    'i18n.components.dashboards.dashboard_share_sheet.sharing',
);

const SHEET_DESCRIPTION = t(
    'i18n.components.dashboards.dashboard_share_sheet.choose_who_else_can_view_this_dashboard',
);

const GRANTEE_TYPE_LABEL = t(
    'i18n.components.dashboards.dashboard_share_sheet.recipient_type',
);

const GRANTEE_LABEL = t(
    'i18n.components.dashboards.dashboard_share_sheet.recipient',
);

const CAN_EDIT_LABEL = t(
    'i18n.components.dashboards.dashboard_share_sheet.allow_editing_instead_of_viewing_only',
);

const CREATE_LABEL = t(
    'i18n.components.dashboards.dashboard_share_sheet.share',
);

const EMPTY_SHARES = t(
    'i18n.components.dashboards.dashboard_share_sheet.this_dashboard_has_not_been_shared_with_anyone_yet',
);

const EDIT_BADGE = t(
    'i18n.components.dashboards.dashboard_share_sheet.can_edit',
);

const VIEW_BADGE = t(
    'i18n.components.dashboards.dashboard_share_sheet.can_view',
);

const UNKNOWN_GRANTEE_TYPE = t(
    'i18n.components.dashboards.dashboard_share_sheet.unknown_type',
);

const GRANTEE_TYPE_ORDER: ShareGranteeType[] = ['user', 'team', 'role'];

const props = defineProps<{
    dashboard: DashboardRow;
    open: boolean;
}>();

const emit = defineEmits<{
    close: [];
}>();

const { isEscalated } = usePermissions();

const {
    shares,
    options,
    isTenantWide,
    loading,
    error,
    tenantWideError,
    load,
    loadOptions,
    create,
    revoke,
    setTenantWide,
} = useDashboardShares(
    props.dashboard.id,
    () => props.dashboard.is_tenant_wide,
);

const granteeType = ref<ShareGranteeType>('user');
const granteeId = ref<string | null>(null);
const canEdit = ref<boolean>(false);
const submitting = ref<boolean>(false);

const granteeTypeOptions: SelectOption[] = GRANTEE_TYPE_ORDER.map((value) => ({
    value,
    label: SHARE_GRANTEE_LABELS[value],
}));

const currentOptions = computed<SelectOption[]>(
    () => options.value[granteeType.value],
);

const createDisabled = computed<boolean>(
    () => submitting.value || granteeId.value === null,
);

function firstOptionValue(type: ShareGranteeType): string | null {
    return options.value[type][0]?.value ?? null;
}

function granteeLabel(share: DashboardShare): string {
    return share.grantee_name ?? SHARE_UNKNOWN_GRANTEE;
}

function granteeTypeLabel(share: DashboardShare): string {
    return share.grantee_type === null
        ? UNKNOWN_GRANTEE_TYPE
        : SHARE_GRANTEE_LABELS[share.grantee_type];
}

function onGranteeTypeChange(value: unknown): void {
    const next = GRANTEE_TYPE_ORDER.find((entry) => entry === value);

    if (next === undefined || next === granteeType.value) {
        return;
    }

    granteeType.value = next;
    granteeId.value = firstOptionValue(next);
}

watch(options, () => {
    granteeId.value = firstOptionValue(granteeType.value);
});

async function onCreate(): Promise<void> {
    const recipient = granteeId.value;

    if (recipient === null || submitting.value) {
        return;
    }

    submitting.value = true;

    try {
        const created = await create({
            grantee_type: granteeType.value,
            grantee_id: recipient,
            can_edit: canEdit.value,
        });

        if (created !== null) {
            canEdit.value = false;
        }
    } finally {
        submitting.value = false;
    }
}

function onRevoke(shareId: string): void {
    void revoke(shareId);
}

function onTenantWideChange(value: unknown): void {
    void setTenantWide(Boolean(value));
}

const loaded = ref<boolean>(false);

watch(
    () => props.open,
    (open) => {
        if (!open || loaded.value || !props.dashboard.can_share) {
            return;
        }

        loaded.value = true;

        void load();
        void loadOptions();
    },
    { immediate: true },
);

function onOpenChange(next: boolean): void {
    if (!next) {
        emit('close');
    }
}
</script>

<template>
    <Sheet :open="props.open" @update:open="onOpenChange">
        <SheetContent side="right" class="w-full sm:max-w-xl">
            <div
                data-share-panel
                class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4"
            >
                <SheetHeader class="px-0">
                    <SheetTitle>{{ SHEET_TITLE }}</SheetTitle>
                    <SheetDescription>
                        {{ SHEET_DESCRIPTION }}
                    </SheetDescription>
                </SheetHeader>

                <template v-if="props.dashboard.can_share">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="dashboard-grantee-type">
                                {{ GRANTEE_TYPE_LABEL }}
                            </Label>
                            <Select
                                :model-value="granteeType"
                                data-grantee-type-select
                                @update:model-value="onGranteeTypeChange"
                            >
                                <SelectTrigger
                                    id="dashboard-grantee-type"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="GRANTEE_TYPE_LABEL"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in granteeTypeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="dashboard-grantee">{{
                                GRANTEE_LABEL
                            }}</Label>
                            <Combobox
                                id="dashboard-grantee"
                                v-model="granteeId"
                                :options="currentOptions"
                                :searchable="
                                    currentOptions.length > SEARCHABLE_THRESHOLD
                                "
                                :aria-label="GRANTEE_LABEL"
                                :placeholder="
                                    t(
                                        'i18n.components.dashboards.dashboard_share_sheet.select_recipient',
                                    )
                                "
                                :empty-label="
                                    t(
                                        'i18n.components.dashboards.dashboard_share_sheet.no_recipients_available',
                                    )
                                "
                                data-grantee-select
                            />
                        </div>
                    </div>

                    <label
                        class="flex items-center gap-2 text-sm"
                        for="dashboard-share-can-edit"
                    >
                        <Checkbox
                            id="dashboard-share-can-edit"
                            data-can-edit-toggle
                            :model-value="canEdit"
                            @update:model-value="canEdit = Boolean($event)"
                        />
                        <span>{{ CAN_EDIT_LABEL }}</span>
                    </label>

                    <p
                        v-if="props.dashboard.has_definer_widget"
                        data-definer-warning
                        class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
                    >
                        {{ DEFINER_SHARE_WARNING }}
                    </p>

                    <div class="flex justify-end">
                        <Button
                            type="button"
                            size="sm"
                            :disabled="createDisabled"
                            data-share-create
                            @click="onCreate"
                        >
                            {{ CREATE_LABEL }}
                        </Button>
                    </div>

                    <InputError :message="error ?? undefined" />

                    <div class="flex flex-col gap-2">
                        <Skeleton v-if="loading" class="h-8 w-full" />
                        <p
                            v-else-if="shares.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{ EMPTY_SHARES }}
                        </p>
                        <template v-else>
                            <div
                                v-for="share in shares"
                                :key="share.id"
                                data-share-row
                                class="flex items-center justify-between gap-2 rounded-md border px-3 py-2"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="truncate text-sm">
                                        {{ granteeLabel(share) }}
                                    </span>
                                    <Badge variant="secondary">
                                        {{ granteeTypeLabel(share) }}
                                    </Badge>
                                    <Badge variant="outline">
                                        {{
                                            share.can_edit
                                                ? EDIT_BADGE
                                                : VIEW_BADGE
                                        }}
                                    </Badge>
                                </div>
                                <IconActionButton
                                    :icon="Trash2"
                                    :label="
                                        t(
                                            'i18n.components.dashboards.dashboard_share_sheet.revoke_access',
                                        )
                                    "
                                    variant="destructive"
                                    data-share-revoke
                                    @click="onRevoke(share.id)"
                                />
                            </div>
                        </template>
                    </div>

                    <div
                        v-if="isEscalated"
                        class="flex flex-col gap-1 border-t pt-4"
                    >
                        <label
                            class="flex items-center gap-2 text-sm"
                            for="dashboard-tenant-wide"
                        >
                            <Checkbox
                                id="dashboard-tenant-wide"
                                data-tenant-wide-toggle
                                :model-value="isTenantWide"
                                @update:model-value="onTenantWideChange"
                            />
                            <span>{{ TENANT_WIDE_LABEL }}</span>
                        </label>
                        <p class="text-xs text-muted-foreground">
                            {{ TENANT_WIDE_HINT }}
                        </p>
                        <p
                            v-if="tenantWideError !== null"
                            data-tenant-wide-error
                            class="text-sm text-destructive"
                        >
                            {{ tenantWideError }}
                        </p>
                    </div>
                </template>
            </div>
        </SheetContent>
    </Sheet>
</template>
