<script setup lang="ts">
import { Pencil, Share2 } from '@lucide/vue';
import { computed, ref, useTemplateRef } from 'vue';
import type { DashboardRow, DashboardWidgetMeta } from '@/domains/analytics/data';
import {
  DASHBOARD_ADD_WIDGET_LABEL,
  DASHBOARD_WITHOUT_DESCRIPTION,
  dashboardActionRefusals,
} from '@/domains/analytics/data';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { resolveActionRefusal } from '@/shared/lib/actionRefusal';
import type { FieldDefinition } from '@/shared/types/fields';
import type { SelectOption } from '@/shared/types/ui';
import CreateButton from '@/shared/ui/CreateButton.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Button } from '@/shared/ui/primitives/button';
import { IconActionButton } from '@/shared/ui/primitives/icon-action-button';
import { useDashboardGridKey } from '../../composables/useDashboardGridKey';
import DashboardDetailsSheet from '../../ui/DashboardDetailsSheet.vue';
import DashboardGrid from '../../ui/DashboardGrid.vue';
import DashboardShareSheet from '../../ui/DashboardShareSheet.vue';

const { t } = useI18n();

const REFRESH_ALL_LABEL = t('i18n.pages.dashboards.show.refresh_all');

const EDIT_LABEL = t('i18n.pages.dashboards.show.edit_basic_information');

const SHARES_LABEL = t('i18n.pages.dashboards.show.sharing');

const props = defineProps<{
  dashboard: DashboardRow;
  widgets: DashboardWidgetMeta[];
  reportOptions: SelectOption[];
  goalOptions: SelectOption[];
  objectTypeOptions: SelectOption[];
  fieldsByType: Record<string, FieldDefinition[]>;
  linkedFieldsByType: Record<string, FieldDefinition[]>;
  segmentsByType: Record<string, SelectOption[]>;
}>();

usePageBreadcrumbs(() => [{ title: props.dashboard.name }]);

const gridRef = useTemplateRef<InstanceType<typeof DashboardGrid>>('gridRef');

const gridKey = useDashboardGridKey(() => props.dashboard.id);

const updateRefusal = computed<string | undefined>(() =>
  props.dashboard.can_update
    ? undefined
    : resolveActionRefusal(props.dashboard.update_reason, dashboardActionRefusals, t),
);

const description = computed<string>(() => props.dashboard.description ?? t(DASHBOARD_WITHOUT_DESCRIPTION));

const shareRefusal = computed<string | undefined>(() =>
  props.dashboard.can_share
    ? undefined
    : resolveActionRefusal(props.dashboard.share_reason, dashboardActionRefusals, t),
);

const detailsOpen = ref<boolean>(false);
const sharesOpen = ref<boolean>(false);

function refreshAll(): void {
  void gridRef.value?.refreshAll();
}

function openWidgetEditor(): void {
  gridRef.value?.openEditor(null);
}

function openDetails(): void {
  detailsOpen.value = true;
}

function openShares(): void {
  sharesOpen.value = true;
}
</script>

<template>
  <PageShell :title="props.dashboard.name" :description="description">
    <template #actions>
      <div class="flex shrink-0 flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          :disabled="gridRef?.isRefreshingAll ?? false"
          data-dashboard-refresh-all
          @click="refreshAll"
        >
          {{ REFRESH_ALL_LABEL }}
        </Button>

        <IconActionButton
          :icon="Share2"
          :label="SHARES_LABEL"
          variant="info"
          :disabled="!props.dashboard.can_share"
          :title="shareRefusal"
          data-dashboard-shares
          @click="openShares"
        />

        <IconActionButton
          :icon="Pencil"
          :label="EDIT_LABEL"
          variant="edit"
          :disabled="!props.dashboard.can_update"
          :title="updateRefusal"
          data-dashboard-edit
          @click="openDetails"
        />

        <CreateButton
          :label="t(DASHBOARD_ADD_WIDGET_LABEL)"
          :disabled="!props.dashboard.can_update"
          :title="updateRefusal"
          data-dashboard-add-widget
          @click="openWidgetEditor"
        />
      </div>
    </template>

    <DashboardGrid
      :key="gridKey"
      ref="gridRef"
      :dashboard-id="props.dashboard.id"
      :widgets="props.widgets"
      :can-update="props.dashboard.can_update"
      :update-reason="updateRefusal"
      :is-designing="true"
      :report-options="props.reportOptions"
      :goal-options="props.goalOptions"
      :object-type-options="props.objectTypeOptions"
      :fields-by-type="props.fieldsByType"
      :linked-fields-by-type="props.linkedFieldsByType"
      :segments-by-type="props.segmentsByType"
    />

    <DashboardDetailsSheet :dashboard="props.dashboard" :open="detailsOpen" @close="detailsOpen = false" />

    <DashboardShareSheet :dashboard="props.dashboard" :open="sharesOpen" @close="sharesOpen = false" />
  </PageShell>
</template>
