<script setup lang="ts">
defineOptions({ inheritAttrs: false });
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import ImportPageController from '@/actions/App/Http/Controllers/Import/ImportPageController';
import CreateButton from '@/components/engine/CreateButton.vue';
import RecordGrid from '@/components/engine/RecordGrid.vue';
import RecordMobileList from '@/components/engine/RecordMobileList.vue';
import RecordSearchBar from '@/components/engine/RecordSearchBar.vue';
import SegmentBar from '@/components/engine/segment/SegmentBar.vue';
import ViewStates from '@/components/engine/ViewStates.vue';
import type { RecordViewState } from '@/components/engine/ViewStates.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

import { usePermissions } from '@/composables/usePermissions';
import { useRecordListMemory } from '@/composables/useRecordListMemory';
import { useRecordSearch } from '@/composables/useRecordSearch';
import { useReportDrillDown } from '@/composables/useReportDrillDown';
import { useSegmentDeepLink } from '@/composables/useSegmentDeepLink';
import type { SegmentSelection } from '@/composables/useSegments';
import { useUserPreferences } from '@/composables/useUserPreferences';
import { recordRouteKey } from '@/lib/recordRouteKey';
import * as notificationRules from '@/routes/notification-rules';
import type { FieldDefinition } from '@/types/fields';
import type { GridColumn } from '@/types/grid';
import type { ObjectTypePreferences } from '@/types/preferences';
import { emptyObjectTypePreferences } from '@/types/preferences';
import type { RecordObjectType, RecordPayload } from '@/types/records';

const { t } = useI18n();

const page = usePage();

const props = withDefaults(
    defineProps<{
        objectType: RecordObjectType;
        columns: GridColumn[];
        fieldDefinitions: FieldDefinition[];
        hasRecords: boolean;
        hasStages?: boolean;
        preference?: ObjectTypePreferences;
    }>(),
    {
        preference: () => emptyObjectTypePreferences(),
    },
);

const { canForObjectType } = usePermissions();
const { settings } = useUserPreferences();

const grid = ref<InstanceType<typeof RecordGrid> | null>(null);
const mobileList = ref<InstanceType<typeof RecordMobileList> | null>(null);

const mode = ref<string>('table');
const isMobile = useMediaQuery('(max-width: 768px)');
const setMode = (value: string): void => {
    mode.value = value;
};
const hasAnyRecords = computed<boolean>(() => props.hasRecords);

const { activeSegmentId, syncFromUrl, applySegment, clear, restoreLastUsed } =
    useSegmentDeepLink(props.objectType, {
        lastSegmentId: props.preference.lastSegmentId,
        lastFilter: props.preference.lastFilter,
    });

const { active: drillDown, clear: clearDrillDown } = useReportDrillDown();

const {
    draft: searchDraft,
    term: searchTerm,
    setDraft: setSearchDraft,
    clear: clearSearch,
} = useRecordSearch();

const listMemory = useRecordListMemory(props.objectType.id);

const onApplySegment = (selection: SegmentSelection): void => {
    clearDrillDown();
    applySegment(selection.segment.id);
};

const clearSegment = (): void => {
    clearDrillDown();
    clear();
};

const recallNarrowedList = (): void => {
    const remembered = listMemory.recall();

    if (remembered === null || drillDown.value !== null) {
        return;
    }

    if (activeSegmentId.value === null && remembered.segmentId !== null) {
        applySegment(remembered.segmentId);
    }

    if (searchDraft.value === '' && remembered.search !== '') {
        setSearchDraft(remembered.search);
    }
};

onMounted(async () => {
    syncFromUrl();
    await restoreLastUsed();
    recallNarrowedList();

    watch([activeSegmentId, searchDraft], () => {
        listMemory.remember({
            segmentId: activeSegmentId.value,
            search: searchDraft.value,
        });
    });
});

const tableState = ref<RecordViewState>('loading');
const mobileState = ref<RecordViewState>('loading');

const openCreate = (createObjectType?: RecordObjectType): void => {
    router.visit(
        RecordsController.create.url({
            objectType: (createObjectType ?? props.objectType).slug,
        }),
    );
};

const openRecord = (record: RecordPayload): void => {
    router.visit(
        RecordsController.show.url({ record: recordRouteKey(record) }),
    );
};
</script>

<template>
    <Head :title="props.objectType.name" />

    <div class="flex h-full flex-1 flex-col">
        <div
            class="flex shrink-0 flex-wrap items-center justify-between gap-4 px-4 py-3"
        >
            <h1 class="text-lg font-semibold">
                {{ props.objectType.name }}
            </h1>

            <div class="flex flex-wrap items-center gap-2">
                <UiExtensionPoint
                    name="records.views"
                    :context="{
                        ...page.props,
                        ...$attrs,
                        ...props,
                        segmentId: activeSegmentId,
                        search: searchTerm,
                        setMode,
                        openRecord,
                        create: openCreate,
                    }"
                />
                <Button
                    v-if="canForObjectType(props.objectType.slug, 'import')"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="
                            ImportPageController.wizard.url({
                                objectType: props.objectType.slug,
                            })
                        "
                    >
                        {{ t('i18n.pages.records.index.import') }}
                    </Link>
                </Button>

                <Button
                    v-if="
                        canForObjectType(props.objectType.slug, 'rules.manage')
                    "
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="
                            notificationRules.edit.url({
                                objectType: props.objectType.slug,
                            })
                        "
                    >
                        {{ t('i18n.pages.records.index.rules') }}
                    </Link>
                </Button>

                <CreateButton
                    v-if="canForObjectType(props.objectType.slug, 'create')"
                    :object-type="props.objectType"
                    @create="openCreate"
                />
            </div>
        </div>

        <p
            v-if="drillDown !== null"
            data-drilldown-notice
            class="shrink-0 px-4 pb-2 text-xs text-muted-foreground"
        >
            {{
                t(
                    'i18n.pages.records.index.this_list_shows_records_in_the_selected_group_if',
                )
            }}
        </p>

        <div
            v-if="hasAnyRecords"
            class="flex shrink-0 flex-wrap items-center gap-2 border-b px-4 py-2"
        >
            <RecordSearchBar
                :model-value="searchDraft"
                class="w-full min-w-48 flex-1 sm:w-auto"
                @update:model-value="setSearchDraft"
                @clear="clearSearch"
            />

            <SegmentBar
                :object-type="props.objectType"
                :fields="props.fieldDefinitions"
                :active-segment-id="activeSegmentId"
                @apply="onApplySegment"
                @clear="clearSegment"
            />
        </div>

        <div
            v-if="!isMobile && mode === 'table'"
            class="relative min-h-0 flex-1"
        >
            <Transition name="record-view">
                <div
                    id="record-view-panel-table"
                    v-show="mode === 'table'"
                    class="absolute inset-0"
                >
                    <RecordGrid
                        v-if="hasAnyRecords"
                        ref="grid"
                        :object-type="props.objectType"
                        :columns="props.columns"
                        :field-definitions="props.fieldDefinitions"
                        :segment-id="activeSegmentId"
                        :search="searchTerm"
                        :drill-down="drillDown"
                        :column-state="props.preference.columnState"
                        :hierarchy-order="props.preference.hierarchyOrder"
                        :page-size="settings.pageSize"
                        @open-record="openRecord"
                        @state="tableState = $event"
                    />
                    <ViewStates
                        :state="hasAnyRecords ? tableState : 'empty'"
                        skeleton-variant="table"
                        empty-kind="no-records"
                        :can-create="
                            canForObjectType(props.objectType.slug, 'create')
                        "
                        @retry="grid?.refresh()"
                        @create="openCreate()"
                    />
                </div>
            </Transition>
        </div>

        <div v-else-if="mode === 'table'" class="relative min-h-0 flex-1">
            <RecordMobileList
                ref="mobileList"
                :object-type="props.objectType"
                :columns="props.columns"
                :field-definitions="props.fieldDefinitions"
                :segment-id="activeSegmentId"
                :search="searchTerm"
                :page-size="settings.pageSize"
                @open-record="openRecord"
                @state="mobileState = $event"
            />
            <ViewStates
                :state="mobileState"
                skeleton-variant="list"
                empty-kind="no-records"
                :can-create="canForObjectType(props.objectType.slug, 'create')"
                @retry="mobileList?.reload()"
                @create="openCreate()"
            />
        </div>
        <div
            id="record-extension-view"
            v-show="mode !== 'table'"
            class="relative min-h-0 flex-1"
        />
    </div>
</template>

<style scoped>
.record-view-enter-active,
.record-view-leave-active {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}

.record-view-enter-from {
    opacity: 0;
    transform: translateY(6px);
}

.record-view-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

.axis-picker-enter-active,
.axis-picker-leave-active {
    transition:
        width 200ms ease,
        opacity 160ms ease,
        transform 200ms ease;
}

.axis-picker-enter-from,
.axis-picker-leave-to {
    width: 0;
    opacity: 0;
    transform: translateX(8px);
}

@media (prefers-reduced-motion: reduce) {
    .record-view-enter-active,
    .record-view-leave-active,
    .axis-picker-enter-active,
    .axis-picker-leave-active {
        transition: none;
    }
}
</style>
