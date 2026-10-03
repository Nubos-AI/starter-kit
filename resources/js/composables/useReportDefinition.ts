import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { segmentPrefill } from '@/routes/reports';
import type {
    ReportAggregation,
    ReportExecutionMode,
    ReportListRow,
    ReportPresentation,
} from '@/types/reports';
import { hydratableFilterTree, normalizeFilterTree } from '@/types/reports';
import type { SelectOption } from '@/types/ui';

export type ReportPrefillState = 'idle' | 'loading' | 'error';

export interface UseReportDefinitionOptions {
    report: ReportListRow | null;
    objectTypeOptions: SelectOption[];
}

export interface UseReportDefinitionReturn {
    name: Ref<string>;
    description: Ref<string>;
    objectTypeId: Ref<string>;
    aggregationType: Ref<ReportAggregation>;
    aggregationFieldKey: Ref<string | null>;
    groupByFieldKey: Ref<string | null>;
    groupByBucket: Ref<string | null>;
    seriesFieldKey: Ref<string | null>;
    chartType: Ref<ReportPresentation>;
    executionMode: Ref<ReportExecutionMode>;
    filterSeed: Ref<FilterGroupNode | undefined>;
    filterTree: Ref<FilterGroupNode | null>;
    filterKey: ComputedRef<string>;
    prefillState: Ref<ReportPrefillState>;
    snapshot: () => unknown;
    savePayload: () => Record<string, unknown>;
    previewPayload: () => Record<string, unknown>;
    applySegmentPrefill: (segmentId: string) => Promise<void>;
}

interface SegmentPrefillPayload {
    objectTypeId: string | null;
    filterTree: FilterGroupNode | undefined;
}

function blankToNull(value: string | null): string | null {
    return value === null || value.trim() === '' ? null : value;
}

function readPrefill(body: unknown): SegmentPrefillPayload | null {
    if (body === null || typeof body !== 'object' || !('data' in body)) {
        return null;
    }

    const data = body.data;

    if (data === null || typeof data !== 'object') {
        return null;
    }

    const objectTypeId =
        'object_type_id' in data && typeof data.object_type_id === 'string'
            ? data.object_type_id
            : null;

    return {
        objectTypeId,
        filterTree: hydratableFilterTree(
            'filter_definition' in data ? data.filter_definition : null,
        ),
    };
}

export function useReportDefinition(
    options: UseReportDefinitionOptions,
): UseReportDefinitionReturn {
    const report = options.report;
    const isCreate = report === null;

    const name = ref<string>(report?.name ?? '');
    const description = ref<string>(report?.description ?? '');
    const objectTypeId = ref<string>(
        report?.object_type_id ?? options.objectTypeOptions[0]?.value ?? '',
    );
    const aggregationType = ref<ReportAggregation>(
        report?.aggregation_type ?? 'count',
    );
    const aggregationFieldKey = ref<string | null>(
        report?.aggregation_field_key ?? null,
    );
    const groupByFieldKey = ref<string | null>(
        report?.group_by_field_key ?? null,
    );
    const groupByBucket = ref<string | null>(report?.group_by_bucket ?? null);
    const seriesFieldKey = ref<string | null>(report?.series_field_key ?? null);
    const chartType = ref<ReportPresentation>(report?.chart_type ?? 'bar');
    const executionMode = ref<ReportExecutionMode>(
        report?.execution_mode ?? 'viewer',
    );

    const seed = hydratableFilterTree(report?.filter_definition);

    const filterSeed = ref<FilterGroupNode | undefined>(seed);
    const filterTree = ref<FilterGroupNode | null>(seed ?? null);
    const prefillToken = ref<number>(0);
    const prefillState = ref<ReportPrefillState>('idle');

    const filterKey = computed<string>(
        () => `${objectTypeId.value}:${prefillToken.value}`,
    );

    watch(
        objectTypeId,
        () => {
            aggregationFieldKey.value = null;
            groupByFieldKey.value = null;
            groupByBucket.value = null;
            seriesFieldKey.value = null;
            filterSeed.value = undefined;
            filterTree.value = null;
        },
        { flush: 'sync' },
    );

    function snapshot(): unknown {
        return {
            name: name.value,
            description: description.value,
            objectTypeId: objectTypeId.value,
            aggregationType: aggregationType.value,
            aggregationFieldKey: aggregationFieldKey.value,
            groupByFieldKey: groupByFieldKey.value,
            groupByBucket: groupByBucket.value,
            seriesFieldKey: seriesFieldKey.value,
            chartType: chartType.value,
            executionMode: executionMode.value,
            filterTree: normalizeFilterTree(filterTree.value),
        };
    }

    function definitionKeys(): Record<string, unknown> {
        return {
            filter_definition: normalizeFilterTree(filterTree.value),
            aggregation_type: aggregationType.value,
            aggregation_field_key: blankToNull(aggregationFieldKey.value),
            group_by_field_key: blankToNull(groupByFieldKey.value),
            group_by_bucket: blankToNull(groupByBucket.value),
            series_field_key: blankToNull(seriesFieldKey.value),
        };
    }

    function savePayload(): Record<string, unknown> {
        const payload: Record<string, unknown> = {
            name: name.value,
            description: blankToNull(description.value),
            ...definitionKeys(),
            chart_type: chartType.value,
            execution_mode: executionMode.value,
        };

        if (isCreate) {
            payload.object_type_id = objectTypeId.value;
        }

        return payload;
    }

    function previewPayload(): Record<string, unknown> {
        return {
            object_type_id: objectTypeId.value,
            ...definitionKeys(),
        };
    }

    async function applySegmentPrefill(segmentId: string): Promise<void> {
        prefillState.value = 'loading';

        try {
            const response = await fetch(
                segmentPrefill.url({ segment: segmentId }),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: buildHeaders({ hasBody: false }),
                },
            );

            if (!response.ok) {
                prefillState.value = 'error';

                return;
            }

            const copied = readPrefill(await response.json());

            if (copied === null) {
                prefillState.value = 'error';

                return;
            }

            if (isCreate && copied.objectTypeId !== null) {
                objectTypeId.value = copied.objectTypeId;
            }

            filterSeed.value = copied.filterTree;
            filterTree.value = copied.filterTree ?? null;
            prefillToken.value += 1;
            prefillState.value = 'idle';
        } catch {
            prefillState.value = 'error';
        }
    }

    return {
        name,
        description,
        objectTypeId,
        aggregationType,
        aggregationFieldKey,
        groupByFieldKey,
        groupByBucket,
        seriesFieldKey,
        chartType,
        executionMode,
        filterSeed,
        filterTree,
        filterKey,
        prefillState,
        snapshot,
        savePayload,
        previewPayload,
        applySegmentPrefill,
    };
}
