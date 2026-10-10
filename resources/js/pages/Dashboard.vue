<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { LayoutDashboard, Pencil } from '@lucide/vue';
import { computed } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import DefaultDashboardSelectionController from '@/actions/App/Http/Controllers/Dashboards/DefaultDashboardSelectionController';
import CreateButton from '@/components/CreateButton.vue';
import DashboardGrid from '@/components/dashboards/DashboardGrid.vue';
import EmptyState from '@/components/engine/state/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { IconActionButton } from '@/components/ui/icon-action-button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { dashboard as dashboardRoute } from '@/routes';
import type { DashboardRow, DashboardWidgetMeta } from '@/types/dashboards';
import type { FieldDefinition } from '@/types/fields';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const PAGE_TITLE = t('i18n.pages.dashboard.home');

const PAGE_DESCRIPTION = t(
    'i18n.pages.dashboard.your_default_dashboard_at_a_glance',
);

const OPEN_SUFFIX = t('i18n.pages.dashboard.open');

const OPEN_ICON_LABEL = t('i18n.pages.dashboard.edit_dashboard');

const READ_ONLY_REASON = t(
    'i18n.pages.dashboard.edit_this_dashboard_on_its_detail_page',
);

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

const groups = computed<{ label: string; options: SelectOption[] }[]>(() =>
    [
        { label: OWN_GROUP_LABEL, options: props.options.own },
        { label: SHARED_GROUP_LABEL, options: props.options.shared },
    ].filter((group) => group.options.length > 0),
);

const isDefault = computed<boolean>(
    () =>
        props.dashboard !== null &&
        props.dashboard.id === props.defaultDashboardId,
);

function switchTo(dashboardId: string): void {
    if (dashboardId === '' || dashboardId === props.dashboard?.id) {
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
    <Head :title="PAGE_TITLE" />

    <div class="flex flex-col gap-6 p-3">
        <div class="flex items-start justify-between gap-4">
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
                    v-if="props.dashboard.can_update"
                    data-default-dashboard-open
                    :icon="Pencil"
                    :label="OPEN_ICON_LABEL"
                    :title="`${props.dashboard.name} ${OPEN_SUFFIX}`"
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

                <Select
                    data-default-dashboard-switch
                    :model-value="props.dashboard.id"
                    @update:model-value="switchTo($event as string)"
                >
                    <SelectTrigger class="w-56" :aria-label="SWITCH_LABEL">
                        <SelectValue :placeholder="SWITCH_LABEL" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup v-for="group in groups" :key="group.label">
                            <SelectLabel>{{ group.label }}</SelectLabel>
                            <SelectItem
                                v-for="option in group.options"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <DashboardGrid
            v-if="props.dashboard !== null"
            :dashboard-id="props.dashboard.id"
            :widgets="props.widgets"
            :can-update="false"
            :update-reason="READ_ONLY_REASON"
            :report-options="EMPTY_EDITOR_OPTIONS"
            :goal-options="EMPTY_EDITOR_OPTIONS"
            :object-type-options="EMPTY_EDITOR_OPTIONS"
            :fields-by-type="EMPTY_EDITOR_FIELDS"
            :linked-fields-by-type="EMPTY_EDITOR_FIELDS"
            :segments-by-type="EMPTY_EDITOR_SEGMENTS"
        />

        <div
            v-else
            data-default-dashboard-empty
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed px-4 py-10 text-center"
        >
            <EmptyState
                :icon="LayoutDashboard"
                :title="EMPTY_TITLE"
                :description="EMPTY_DESCRIPTION"
                heading-level="h3"
            />

            <CreateButton
                v-if="props.canCreate"
                :label="CREATE_LABEL"
                :href="createHref"
                data-default-dashboard-create
            />
        </div>
    </div>
</template>
