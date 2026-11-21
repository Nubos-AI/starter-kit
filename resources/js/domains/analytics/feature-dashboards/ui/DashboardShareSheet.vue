<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { DashboardRow, DashboardShare, ShareGranteeType } from '@/domains/analytics/data';
import {
  DEFINER_SHARE_WARNING,
  SHARE_GRANTEE_LABELS,
  SHARE_UNKNOWN_GRANTEE,
  TENANT_WIDE_HINT,
  TENANT_WIDE_LABEL,
} from '@/domains/analytics/data';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import { useShareGrantCreation } from '@/shared/composables/useShareGrantCreation';
import type { SelectOption } from '@/shared/types/ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Badge } from '@/shared/ui/primitives/badge';
import { Button } from '@/shared/ui/primitives/button';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { IconActionButton } from '@/shared/ui/primitives/icon-action-button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/shared/ui/primitives/sheet';
import { Skeleton } from '@/shared/ui/primitives/skeleton';
import { useDashboardShares } from '../data/useDashboardShares';

const { t } = useI18n();

const SEARCHABLE_THRESHOLD = 8;

const SHEET_TITLE = t('i18n.components.dashboards.dashboard_share_sheet.sharing');

const SHEET_DESCRIPTION = t('i18n.components.dashboards.dashboard_share_sheet.choose_who_else_can_view_this_dashboard');

const GRANTEE_TYPE_LABEL = t('i18n.components.dashboards.dashboard_share_sheet.recipient_type');

const GRANTEE_LABEL = t('i18n.components.dashboards.dashboard_share_sheet.recipient');

const CAN_EDIT_LABEL = t('i18n.components.dashboards.dashboard_share_sheet.allow_editing_instead_of_viewing_only');

const CREATE_LABEL = t('i18n.components.dashboards.dashboard_share_sheet.share');

const EMPTY_SHARES = t(
  'i18n.components.dashboards.dashboard_share_sheet.this_dashboard_has_not_been_shared_with_anyone_yet',
);

const EDIT_BADGE = t('i18n.components.dashboards.dashboard_share_sheet.can_edit');

const VIEW_BADGE = t('i18n.components.dashboards.dashboard_share_sheet.can_view');

const UNKNOWN_GRANTEE_TYPE = t('i18n.components.dashboards.dashboard_share_sheet.unknown_type');

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
} = useDashboardShares(props.dashboard.id, () => props.dashboard.is_tenant_wide);

const granteeType = ref<ShareGranteeType>('user');
const granteeId = ref<string | null>(null);
const { canEdit, submitting, submit } = useShareGrantCreation(create);

const granteeTypeOptions: SelectOption[] = GRANTEE_TYPE_ORDER.map((value) => ({
  value,
  label: t(SHARE_GRANTEE_LABELS[value]),
}));

const currentOptions = computed<SelectOption[]>(() => options.value[granteeType.value]);

const createDisabled = computed<boolean>(() => submitting.value || granteeId.value === null);

function firstOptionValue(type: ShareGranteeType): string | null {
  return options.value[type][0]?.value ?? null;
}

function granteeLabel(share: DashboardShare): string {
  return share.grantee_name ?? t(SHARE_UNKNOWN_GRANTEE);
}

function granteeTypeLabel(share: DashboardShare): string {
  return share.grantee_type === null ? UNKNOWN_GRANTEE_TYPE : t(SHARE_GRANTEE_LABELS[share.grantee_type]);
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

function onCreate(): void {
  void submit(granteeType.value, granteeId.value);
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
      <div data-share-panel class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4">
        <SheetHeader class="px-0">
          <SheetTitle>{{ SHEET_TITLE }}</SheetTitle>
          <SheetDescription>
            {{ SHEET_DESCRIPTION }}
          </SheetDescription>
        </SheetHeader>

        <template v-if="props.dashboard.can_share">
          <div class="grid gap-4 sm:grid-cols-2">
            <FormField for="dashboard-grantee-type" :label="GRANTEE_TYPE_LABEL">
              <Select :model-value="granteeType" data-grantee-type-select @update:model-value="onGranteeTypeChange">
                <SelectTrigger id="dashboard-grantee-type" class="w-full">
                  <SelectValue :placeholder="GRANTEE_TYPE_LABEL" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="option in granteeTypeOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </FormField>

            <FormField for="dashboard-grantee" :label="GRANTEE_LABEL">
              <Combobox
                id="dashboard-grantee"
                v-model="granteeId"
                :options="currentOptions"
                :searchable="currentOptions.length > SEARCHABLE_THRESHOLD"
                :aria-label="GRANTEE_LABEL"
                :placeholder="t('i18n.components.dashboards.dashboard_share_sheet.select_recipient')"
                :empty-label="t('i18n.components.dashboards.dashboard_share_sheet.no_recipients_available')"
                data-grantee-select
              />
            </FormField>
          </div>

          <FormCheckbox id="dashboard-share-can-edit" v-model="canEdit" :label="CAN_EDIT_LABEL" data-can-edit-toggle />

          <p
            v-if="props.dashboard.has_definer_widget"
            data-definer-warning
            class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
          >
            {{ t(DEFINER_SHARE_WARNING) }}
          </p>

          <div class="flex justify-end">
            <Button type="button" size="sm" :disabled="createDisabled" data-share-create @click="onCreate">
              {{ CREATE_LABEL }}
            </Button>
          </div>

          <InputError :message="error ?? undefined" />

          <div class="flex flex-col gap-2">
            <Skeleton v-if="loading" class="h-8 w-full" />
            <EmptyState v-else-if="shares.length === 0" variant="inline" :description="EMPTY_SHARES" />
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
                    {{ share.can_edit ? EDIT_BADGE : VIEW_BADGE }}
                  </Badge>
                </div>
                <IconActionButton
                  :icon="Trash2"
                  :label="t('i18n.components.dashboards.dashboard_share_sheet.revoke_access')"
                  variant="destructive"
                  data-share-revoke
                  @click="onRevoke(share.id)"
                />
              </div>
            </template>
          </div>

          <FormCheckbox
            v-if="isEscalated"
            id="dashboard-tenant-wide"
            :model-value="isTenantWide"
            :label="t(TENANT_WIDE_LABEL)"
            :description="t(TENANT_WIDE_HINT)"
            :error="tenantWideError ?? undefined"
            data-tenant-wide-toggle
            class="border-t pt-4"
            @update:model-value="onTenantWideChange"
          />
        </template>
      </div>
    </SheetContent>
  </Sheet>
</template>
