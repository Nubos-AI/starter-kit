<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { computed } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import DefaultDashboardSelectionController from '@/actions/App/Http/Controllers/Dashboards/DefaultDashboardSelectionController';
import CreateButton from '@/components/CreateButton.vue';
import DashboardEmptyState from '@/components/dashboards/DashboardEmptyState.vue';
import DashboardGrid from '@/components/dashboards/DashboardGrid.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { IconActionButton } from '@/components/ui/icon-action-button';
import { useDashboardGridKey } from '@/composables/useDashboardGridKey';
import { useI18n } from '@/composables/useI18n';
import { dashboard as dashboardRoute } from '@/routes';
import type { DashboardRow, DashboardWidgetMeta } from '@/types/dashboards';
import { resolveDashboardActionRefusal } from '@/types/dashboards';
import type { FieldDefinition } from '@/types/fields';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const PAGE_TITLE = t('i18n.pages.dashboard.home');

const PAGE_DESCRIPTION = t(
    'i18n.pages.dashboard.your_default_dashboard_at_a_glance',
);

const OPEN_SUFFIX = t('i18n.pages.dashboard.open');

const OPEN_ICON_LABEL = t('i18n.pages.dashboard.edit_dashboard');

const EMPTY_TITLE = t('i18n.pages.dashboard.no_dashboard_for_you_yet');

const EMPTY_DESCRIPTION = t(
    'i18n.pages.dashboard.once_a_personal_or_tenant_wide_dashboard_is_available',
);

const CREATE_LABEL = t('i18n.pages.dashboard.create_dashboard');

const EMPTY_EDITOR_OPTIONS: SelectOption[] = [];

const EMPTY_EDITOR_FIELDS: Record<string, FieldDefinition[]> = {};

const EMPTY_EDITOR_SEGMENTS: Record<string, SelectOption[]> = {};

const OWN_GROUP_LABEL = t('i18n.pages.dashboard.my_dashboards');

const SHARED_GROUP_LABEL = t('i18n.pages.dashboard.shared_with_me');

const MAKE_DEFAULT_LABEL = t('i18n.pages.dashboard.set_as_default');

const MAKE_DEFAULT_DONE_HINT = t(
    'i18n.pages.dashboard.this_dashboard_is_already_your_default',
);

const SWITCH_LABEL = t('i18n.pages.dashboard.select_dashboard');

interface DashboardOptions {
    own: SelectOption[];
    shared: SelectOption[];
}

const props = defineProps<{
    dashboard: DashboardRow | null;
    widgets: DashboardWidgetMeta[];
    options: DashboardOptions;
    defaultDashboardId: string | null;
    canCreate: boolean;
}>();

const createHref = computed<string>(() => DashboardsController.create.url());

const gridKey = useDashboardGridKey(() => props.dashboard?.id ?? null);

const switchOptions = computed<SelectOption[]>(() => [
    ...props.options.own.map((option) => ({
        ...option,
        description: OWN_GROUP_LABEL,
    })),
    ...props.options.shared.map((option) => ({
        ...option,
        description: SHARED_GROUP_LABEL,
    })),
]);

const openTitle = computed<string | undefined>(() => {
    const current = props.dashboard;

    if (current === null) {
        return undefined;
    }

    return current.can_update
        ? `${current.name} ${OPEN_SUFFIX}`
        : resolveDashboardActionRefusal(current.update_reason);
});

const isDefault = computed<boolean>(
    () =>
        props.dashboard !== null &&
        props.dashboard.id === props.defaultDashboardId,
);

function switchTo(dashboardId: unknown): void {
    if (
        typeof dashboardId !== 'string' ||
        dashboardId === '' ||
        dashboardId === props.dashboard?.id
    ) {
        return;
    }

    router.visit(
        dashboardRoute.url(undefined, { query: { dashboard: dashboardId } }),
    );
}

function makeDefault(): void {
    const current = props.dashboard;

    if (current === null || isDefault.value) {
        return;
    }

    router.put(
        DefaultDashboardSelectionController.url({ dashboard: current.id }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head :title="PAGE_TITLE" />

        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                variant="small"
                :title="PAGE_TITLE"
                :description="PAGE_DESCRIPTION"
            />

            <div
                v-if="props.dashboard !== null"
                data-default-dashboard-controls
                class="flex shrink-0 flex-wrap items-center gap-2"
            >
                <IconActionButton
                    data-default-dashboard-open
                    :data-default-dashboard-open-disabled="
                        props.dashboard.can_update ? undefined : ''
                    "
                    :icon="Pencil"
                    :label="OPEN_ICON_LABEL"
                    :title="openTitle"
                    :disabled="!props.dashboard.can_update"
                    variant="edit"
                    :href="
                        DashboardsController.show.url({
                            dashboard: props.dashboard.id,
                        })
                    "
                />

                <Button
                    data-default-dashboard-make-default
                    variant="outline"
                    :disabled="isDefault"
                    :title="isDefault ? MAKE_DEFAULT_DONE_HINT : undefined"
                    @click="makeDefault"
                >
                    {{ MAKE_DEFAULT_LABEL }}
                </Button>

                <Combobox
                    data-default-dashboard-switch
                    class="w-full sm:w-56"
                    :model-value="props.dashboard.id"
                    :options="switchOptions"
                    :searchable="switchOptions.length > 8"
                    :aria-label="SWITCH_LABEL"
                    :placeholder="SWITCH_LABEL"
                    @update:model-value="switchTo"
                />
            </div>
        </div>

        <DashboardGrid
            v-if="props.dashboard !== null"
            :key="gridKey"
            :dashboard-id="props.dashboard.id"
            :widgets="props.widgets"
            :can-update="false"
            :is-designing="false"
            :report-options="EMPTY_EDITOR_OPTIONS"
            :goal-options="EMPTY_EDITOR_OPTIONS"
            :object-type-options="EMPTY_EDITOR_OPTIONS"
            :fields-by-type="EMPTY_EDITOR_FIELDS"
            :linked-fields-by-type="EMPTY_EDITOR_FIELDS"
            :segments-by-type="EMPTY_EDITOR_SEGMENTS"
        />

        <DashboardEmptyState
            v-else
            data-default-dashboard-empty
            :title="EMPTY_TITLE"
            :description="EMPTY_DESCRIPTION"
            heading-level="h3"
        >
            <CreateButton
                v-if="props.canCreate"
                :label="CREATE_LABEL"
                :href="createHref"
                data-default-dashboard-create
            />
        </DashboardEmptyState>
    </div>
</template>
