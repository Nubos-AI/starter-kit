<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Pencil, Share2 } from '@lucide/vue';
import { computed, ref, useTemplateRef } from 'vue';
import CreateButton from '@/components/CreateButton.vue';
import DashboardDetailsSheet from '@/components/dashboards/DashboardDetailsSheet.vue';
import DashboardGrid from '@/components/dashboards/DashboardGrid.vue';
import DashboardShareSheet from '@/components/dashboards/DashboardShareSheet.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { IconActionButton } from '@/components/ui/icon-action-button';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import type { DashboardRow, DashboardWidgetMeta } from '@/types/dashboards';
import {
    DASHBOARD_ADD_WIDGET_LABEL,
    DASHBOARD_WITHOUT_DESCRIPTION,
    resolveDashboardActionRefusal,
} from '@/types/dashboards';
import type { FieldDefinition } from '@/types/fields';
import type { SelectOption } from '@/types/ui';

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

const updateRefusal = computed<string | undefined>(() =>
    props.dashboard.can_update
        ? undefined
        : resolveDashboardActionRefusal(props.dashboard.update_reason),
);

const description = computed<string>(
    () => props.dashboard.description ?? DASHBOARD_WITHOUT_DESCRIPTION,
);

const shareRefusal = computed<string | undefined>(() =>
    props.dashboard.can_share
        ? undefined
        : resolveDashboardActionRefusal(props.dashboard.share_reason),
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
    <Head :title="props.dashboard.name" />

    <div class="flex flex-col gap-6 p-3">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="props.dashboard.name"
                :description="description"
            />

            <div class="flex shrink-0 items-center gap-2">
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
                    :label="DASHBOARD_ADD_WIDGET_LABEL"
                    :disabled="!props.dashboard.can_update"
                    :title="updateRefusal"
                    data-dashboard-add-widget
                    @click="openWidgetEditor"
                />
            </div>
        </div>

        <DashboardGrid
            ref="gridRef"
            :dashboard-id="props.dashboard.id"
            :widgets="props.widgets"
            :can-update="props.dashboard.can_update"
            :update-reason="updateRefusal"
            :report-options="props.reportOptions"
            :goal-options="props.goalOptions"
            :object-type-options="props.objectTypeOptions"
            :fields-by-type="props.fieldsByType"
            :linked-fields-by-type="props.linkedFieldsByType"
            :segments-by-type="props.segmentsByType"
        />

        <DashboardDetailsSheet
            :dashboard="props.dashboard"
            :open="detailsOpen"
            @close="detailsOpen = false"
        />

        <DashboardShareSheet
            :dashboard="props.dashboard"
            :open="sharesOpen"
            @close="sharesOpen = false"
        />
    </div>
</template>
