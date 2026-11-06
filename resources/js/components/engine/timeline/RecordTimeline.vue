<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import RecordTimelineController from '@/actions/App/Http/Controllers/Engine/RecordTimelineController';
import { TIMELINE_TABS } from '@/components/engine/timeline/sourceLabels';
import TimelineEntryRow from '@/components/engine/timeline/TimelineEntryRow.vue';
import TimelineFilters from '@/components/engine/timeline/TimelineFilters.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useI18n } from '@/composables/useI18n';
import { useModuleOptions } from '@/composables/useModuleOptions';
import type { FieldDefinition } from '@/types/fields';
import type { TimelineEntry, TimelineFilterState } from '@/types/timeline';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const MAX_AUTOMATIC_FOLLOW_UP_PAGES = 3;

const ALL_SOURCES = 'all';

const TIMELINE_TITLE = t(
    'i18n.components.engine.timeline.record_timeline.timeline',
);

const TIMELINE_DESCRIPTION = t(
    'i18n.components.engine.timeline.record_timeline.everything_that_happened_to_this_record_in_one_timeline',
);

const EMPTY_FILTER_STATE: TimelineFilterState = {
    occurredFrom: null,
    occurredTo: null,
    actorType: null,
    actorId: null,
};

const props = defineProps<{
    recordId: string;
    fields: FieldDefinition[];
}>();

const page = usePage();
const moduleTabs = useModuleOptions('timeline.tabs');
const timelineTabs = computed(() => [
    ...TIMELINE_TABS.slice(0, -1),
    ...moduleTabs.value.map((tab) => ({
        ...tab,
        sources: [String(tab.value)],
    })),
    ...TIMELINE_TABS.slice(-1),
]);

const activeTab = ref<string>(ALL_SOURCES);
const filterState = ref<TimelineFilterState>({ ...EMPTY_FILTER_STATE });
const hasMounted = ref<boolean>(false);
const openVisits = ref<number>(0);
const automaticFollowUps = ref<number>(0);

const loading = computed<boolean>(
    () => !hasMounted.value || openVisits.value > 0,
);

const entries = computed<TimelineEntry[]>(() =>
    readEntries(page.props.timelineEntries),
);

const nextCursor = computed<string | null>(() => {
    const nextPage = page.scrollProps?.timelineEntries?.nextPage;

    return typeof nextPage === 'string' ? nextPage : null;
});

const activeSources = computed<string[]>(
    () =>
        timelineTabs.value.find((tab) => tab.value === activeTab.value)
            ?.sources ?? [],
);

const hasFilter = computed<boolean>(
    () =>
        activeTab.value !== ALL_SOURCES ||
        Object.values(filterState.value).some((value) => value !== null),
);

const actorOptions = computed<SelectOption[]>(() => {
    const seen = new Map<string, SelectOption>();

    for (const entry of entries.value) {
        const id = entry.actorId;

        if (id === null || seen.has(id)) {
            continue;
        }

        const label = entry.actorLabel ?? id;

        seen.set(id, { value: id, label, avatar: { name: label } });
    }

    return [...seen.values()];
});

function readEntries(value: unknown): TimelineEntry[] {
    if (typeof value !== 'object' || value === null || !('data' in value)) {
        return [];
    }

    const data = value.data;

    return Array.isArray(data) ? data : [];
}

function buildQuery(reset: boolean): Record<string, FormDataConvertible> {
    const query: Record<string, FormDataConvertible> = {};
    const { occurredFrom, occurredTo, actorType, actorId } = filterState.value;

    if (activeSources.value.length > 0) {
        query.sources = [...activeSources.value];
    }

    if (occurredFrom !== null) {
        query.occurredFrom = occurredFrom;
    }

    if (occurredTo !== null) {
        query.occurredTo = occurredTo;
    }

    if (actorType !== null) {
        query.actorType = actorType;

        if (actorId !== null) {
            query.actorId = actorId;
        }
    }

    if (!reset && nextCursor.value !== null) {
        query.timelineCursor = nextCursor.value;
    }

    return query;
}

function load(reset: boolean): void {
    if (!timelineTabs.value.some((tab) => tab.value === activeTab.value)) {
        return;
    }

    openVisits.value += 1;

    router.get(
        RecordTimelineController.url({ record: props.recordId }),
        buildQuery(reset),
        {
            only: ['timelineEntries'],
            reset: reset ? ['timelineEntries'] : [],
            preserveState: true,
            preserveScroll: true,
            preserveUrl: true,
            onSuccess: () => {
                followUpEmptyPage();
            },
            onFinish: () => {
                openVisits.value -= 1;
            },
        },
    );
}

function followUpEmptyPage(): void {
    if (
        entries.value.length > 0 ||
        nextCursor.value === null ||
        automaticFollowUps.value >= MAX_AUTOMATIC_FOLLOW_UP_PAGES
    ) {
        return;
    }

    automaticFollowUps.value += 1;
    load(false);
}

function reload(): void {
    automaticFollowUps.value = 0;
    load(true);
}

function onTabChange(value: string | number): void {
    activeTab.value = String(value);
    reload();
}

function onFilterChange(state: TimelineFilterState): void {
    filterState.value = state;
    reload();
}

function onResetFilters(): void {
    activeTab.value = ALL_SOURCES;
    filterState.value = { ...EMPTY_FILTER_STATE };
    reload();
}

function onLoadMore(): void {
    automaticFollowUps.value = 0;
    load(false);
}

watch(
    () => page.props.timelineEntries,
    (value) => {
        if (value === undefined) {
            reload();
        }
    },
);

onMounted(() => {
    hasMounted.value = true;
    reload();
});

defineExpose({ reload });
</script>

<template>
    <section data-record-timeline class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <h2 class="text-base font-semibold">{{ TIMELINE_TITLE }}</h2>
            <p class="text-sm text-muted-foreground">
                {{ TIMELINE_DESCRIPTION }}
            </p>
        </div>

        <Tabs
            extension-point="tabs.record-timeline"
            :extension-context="$props"
            :model-value="activeTab"
            @update:model-value="onTabChange"
        >
            <TabsList class="w-full">
                <TabsTrigger
                    v-for="tab in timelineTabs"
                    :key="tab.value"
                    :value="tab.value"
                    :data-timeline-tab="tab.value"
                >
                    {{ tab.label }}
                </TabsTrigger>
            </TabsList>
        </Tabs>

        <template v-if="timelineTabs.some((tab) => tab.value === activeTab)">
            <TimelineFilters
                :filter-state="filterState"
                :actor-options="actorOptions"
                @update:filter-state="onFilterChange"
            />

            <div
                v-if="loading"
                data-timeline-skeleton
                class="flex flex-col gap-2"
            >
                <Skeleton
                    v-for="placeholder in 3"
                    :key="placeholder"
                    class="h-12 w-full rounded-md"
                    aria-hidden="true"
                />
                <p class="sr-only" role="status">
                    {{
                        t(
                            'i18n.components.engine.timeline.record_timeline.loading_timeline',
                        )
                    }}
                </p>
            </div>

            <div
                v-else-if="entries.length === 0"
                data-timeline-empty
                class="flex flex-col items-center gap-3 py-6"
            >
                <p class="text-center text-sm text-muted-foreground">
                    {{
                        hasFilter
                            ? t(
                                  'i18n.components.engine.timeline.record_timeline.there_are_no_events_for_this_selection_yet',
                              )
                            : t(
                                  'i18n.components.engine.timeline.record_timeline.there_are_no_events_for_this_record_yet',
                              )
                    }}
                </p>

                <Button
                    v-if="hasFilter"
                    size="sm"
                    variant="outline"
                    data-timeline-reset-filters
                    @click="onResetFilters"
                >
                    {{
                        t(
                            'i18n.components.engine.timeline.record_timeline.reset_filters',
                        )
                    }}
                </Button>
            </div>

            <ol v-else class="relative flex flex-col pt-1">
                <span
                    aria-hidden="true"
                    class="absolute top-2 bottom-2 left-4 w-px -translate-x-1/2 border-l border-dashed border-border"
                />

                <TimelineEntryRow
                    v-for="entry in entries"
                    :key="entry.id"
                    :entry="entry"
                    :record-id="props.recordId"
                    :fields="props.fields"
                    @changed="reload()"
                />
            </ol>

            <div v-if="nextCursor !== null" class="flex justify-center">
                <Button
                    size="sm"
                    variant="outline"
                    data-timeline-load-more
                    :disabled="loading"
                    @click="onLoadMore"
                >
                    {{
                        t(
                            'i18n.components.engine.timeline.record_timeline.load_more',
                        )
                    }}
                </Button>
            </div>
        </template>
    </section>
</template>
