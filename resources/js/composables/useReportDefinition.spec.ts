import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick } from 'vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useReportDefinition } from '@/composables/useReportDefinition';
import { segmentPrefill } from '@/routes/reports';
import type { ReportListRow } from '@/types/reports';
import type { SelectOption } from '@/types/ui';

type Definition = ReturnType<typeof useReportDefinition>;

interface ScopedDefinition {
    definition: Definition;
    stop: () => void;
}

const PRIMARY_TYPE = '01OBJECTTYPE000000000001';
const SECOND_TYPE = '01OBJECTTYPE000000000002';
const SEGMENT_ID = '01SEGMENT00000000000001';

const OBJECT_TYPE_OPTIONS: SelectOption[] = [
    { value: PRIMARY_TYPE, label: 'Deals' },
    { value: SECOND_TYPE, label: 'Contacts' },
];

const SEGMENT_TREE: FilterGroupNode = {
    combinator: 'or',
    conditions: [{ field: 'stage', operator: 'equals', value: 'lost' }],
};

function tree(conditions: FilterGroupNode['conditions'] = []): FilterGroupNode {
    return { combinator: 'and', conditions };
}

function storedTree(): FilterGroupNode {
    return tree([{ field: 'stage', operator: 'equals', value: 'won' }]);
}

function reportRow(overrides: Partial<ReportListRow> = {}): ReportListRow {
    return {
        id: '01REPORT0000000000000001',
        name: 'Pipeline',
        description: 'Nach Phase',
        object_type_id: PRIMARY_TYPE,
        object_type: { id: PRIMARY_TYPE, slug: 'deals', name: 'Deals' },
        filter_definition: storedTree(),
        aggregation_type: 'sum',
        aggregation_field_key: 'amount',
        group_by_field_key: 'closed_at',
        group_by_bucket: 'month',
        series_field_key: 'stage',
        chart_type: 'bar',
        execution_mode: 'viewer',
        is_owner: true,
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

type FetchHandler = (input: string, init: RequestInit) => Promise<Response>;

function stubFetch(handler: FetchHandler) {
    const mock = vi.fn(handler);

    vi.stubGlobal('fetch', mock);

    return mock;
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function scoped(report: ReportListRow | null): ScopedDefinition {
    const scope = effectScope();
    const created = scope.run(() =>
        useReportDefinition({
            report,
            objectTypeOptions: OBJECT_TYPE_OPTIONS,
        }),
    );

    if (created === undefined) {
        throw new Error(
            'The definition composable did not run inside the scope.',
        );
    }

    return { definition: created, stop: () => scope.stop() };
}

function snapshotChangesAfter(mutate: (definition: Definition) => void): {
    changed: boolean;
    stop: () => void;
} {
    const { definition, stop } = scoped(reportRow());

    const before = JSON.stringify(definition.snapshot());
    mutate(definition);
    const after = JSON.stringify(definition.snapshot());

    return { changed: before !== after, stop };
}

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=definition-xsrf-token';
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useReportDefinition — hydration', () => {
    it('adopts every stored value of the report it edits', () => {
        const { definition, stop } = scoped(reportRow());

        expect(definition.name.value).toBe('Pipeline');
        expect(definition.description.value).toBe('Nach Phase');
        expect(definition.objectTypeId.value).toBe(PRIMARY_TYPE);
        expect(definition.aggregationType.value).toBe('sum');
        expect(definition.aggregationFieldKey.value).toBe('amount');
        expect(definition.groupByFieldKey.value).toBe('closed_at');
        expect(definition.groupByBucket.value).toBe('month');
        expect(definition.seriesFieldKey.value).toBe('stage');
        expect(definition.chartType.value).toBe('bar');
        expect(definition.executionMode.value).toBe('viewer');
        expect(definition.filterSeed.value).toEqual(storedTree());

        stop();
    });

    it('turns a stored empty filter into an absent seed instead of crashing the builder', () => {
        const empty = scoped(reportRow({ filter_definition: [] }));
        expect(empty.definition.filterSeed.value).toBeUndefined();
        empty.stop();

        const emptyObject = scoped(reportRow({ filter_definition: {} }));
        expect(emptyObject.definition.filterSeed.value).toBeUndefined();
        emptyObject.stop();

        const missing = scoped(reportRow({ filter_definition: null }));
        expect(missing.definition.filterSeed.value).toBeUndefined();
        missing.stop();
    });

    it('defaults the object type to the first offered option while creating', () => {
        const { definition, stop } = scoped(null);

        expect(definition.objectTypeId.value).toBe(PRIMARY_TYPE);
        expect(definition.name.value).toBe('');
        expect(definition.filterSeed.value).toBeUndefined();
        expect(definition.filterTree.value).toBeNull();

        stop();
    });
});

describe('useReportDefinition — object type change', () => {
    it('clears every field bound selection and the filter tree', async () => {
        const { definition, stop } = scoped(reportRow());

        definition.filterTree.value = storedTree();
        definition.objectTypeId.value = SECOND_TYPE;
        await nextTick();

        expect(definition.aggregationFieldKey.value).toBeNull();
        expect(definition.groupByFieldKey.value).toBeNull();
        expect(definition.groupByBucket.value).toBeNull();
        expect(definition.seriesFieldKey.value).toBeNull();
        expect(definition.filterSeed.value).toBeUndefined();
        expect(definition.filterTree.value).toBeNull();

        stop();
    });

    it('remounts the filter builder by changing the filter key', async () => {
        const { definition, stop } = scoped(reportRow());

        const before = definition.filterKey.value;

        definition.objectTypeId.value = SECOND_TYPE;
        await nextTick();

        expect(definition.filterKey.value).not.toBe(before);
        expect(definition.filterKey.value).toContain(SECOND_TYPE);

        stop();
    });
});

describe('useReportDefinition — segment prefill', () => {
    it('copies the tree out of the data envelope and remounts the builder', async () => {
        const fetchMock = stubFetch(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: {
                        object_type_id: PRIMARY_TYPE,
                        filter_definition: SEGMENT_TREE,
                    },
                }),
            ),
        );

        const { definition, stop } = scoped(reportRow());

        const keyBefore = definition.filterKey.value;

        await definition.applySegmentPrefill(SEGMENT_ID);

        expect(fetchMock.mock.calls[0][0]).toBe(
            segmentPrefill.url({ segment: SEGMENT_ID }),
        );
        expect(definition.filterSeed.value).toEqual(SEGMENT_TREE);
        expect(definition.filterTree.value).toEqual(SEGMENT_TREE);
        expect(definition.filterKey.value).not.toBe(keyBefore);
        expect(definition.prefillState.value).toBe('idle');

        stop();
    });

    it('copies once and keeps no reference to the segment it copied from', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: {
                        object_type_id: PRIMARY_TYPE,
                        filter_definition: SEGMENT_TREE,
                    },
                }),
            ),
        );

        const { definition, stop } = scoped(reportRow());

        await definition.applySegmentPrefill(SEGMENT_ID);

        const savePayload = definition.savePayload();
        const previewPayload = definition.previewPayload();

        expect(definition.filterTree.value).toEqual(SEGMENT_TREE);
        expect(savePayload.filter_definition).toEqual(SEGMENT_TREE);
        expect(Object.keys(savePayload)).not.toContain('segment_id');
        expect(Object.keys(previewPayload)).not.toContain('segment_id');
        expect(JSON.stringify(savePayload)).not.toContain(SEGMENT_ID);
        expect(JSON.stringify(previewPayload)).not.toContain(SEGMENT_ID);

        stop();
    });

    it('adopts the object type of the segment while creating', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: {
                        object_type_id: SECOND_TYPE,
                        filter_definition: SEGMENT_TREE,
                    },
                }),
            ),
        );

        const { definition, stop } = scoped(null);

        await definition.applySegmentPrefill(SEGMENT_ID);

        expect(definition.objectTypeId.value).toBe(SECOND_TYPE);
        expect(definition.filterTree.value).toEqual(SEGMENT_TREE);

        stop();
    });

    it('ignores the object type of the segment while editing, because it is fixed', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: {
                        object_type_id: SECOND_TYPE,
                        filter_definition: SEGMENT_TREE,
                    },
                }),
            ),
        );

        const { definition, stop } = scoped(reportRow());

        await definition.applySegmentPrefill(SEGMENT_ID);

        expect(definition.objectTypeId.value).toBe(PRIMARY_TYPE);
        expect(definition.filterTree.value).toEqual(SEGMENT_TREE);

        stop();
    });

    it('leaves the tree untouched and reports the failure when the endpoint refuses', async () => {
        stubFetch(() =>
            Promise.resolve(jsonResponse(422, { message: 'nope' })),
        );

        const { definition, stop } = scoped(reportRow());

        await definition.applySegmentPrefill(SEGMENT_ID);

        expect(definition.prefillState.value).toBe('error');
        expect(definition.filterSeed.value).toEqual(storedTree());

        stop();
    });

    it('reports the failure when the connection breaks', async () => {
        stubFetch(() => Promise.reject(new TypeError('Failed to fetch')));

        const { definition, stop } = scoped(reportRow());

        await definition.applySegmentPrefill(SEGMENT_ID);

        expect(definition.prefillState.value).toBe('error');
        expect(definition.filterSeed.value).toEqual(storedTree());

        stop();
    });
});

describe('useReportDefinition — payloads', () => {
    it('turns every emptied selection into null without touching the bound refs', () => {
        const { definition, stop } = scoped(reportRow());

        definition.description.value = '';
        definition.aggregationFieldKey.value = '';
        definition.groupByFieldKey.value = '';
        definition.groupByBucket.value = '';
        definition.seriesFieldKey.value = '';

        const payload = definition.savePayload();

        expect(payload.description).toBeNull();
        expect(payload.aggregation_field_key).toBeNull();
        expect(payload.group_by_field_key).toBeNull();
        expect(payload.group_by_bucket).toBeNull();
        expect(payload.series_field_key).toBeNull();

        expect(definition.description.value).toBe('');
        expect(definition.aggregationFieldKey.value).toBe('');
        expect(definition.groupByFieldKey.value).toBe('');
        expect(definition.groupByBucket.value).toBe('');
        expect(definition.seriesFieldKey.value).toBe('');

        stop();
    });

    it('normalises an empty filter tree to null in the payload only', () => {
        const { definition, stop } = scoped(reportRow());

        definition.filterTree.value = tree();

        expect(definition.savePayload().filter_definition).toBeNull();
        expect(definition.previewPayload().filter_definition).toBeNull();
        expect(definition.filterTree.value).toEqual(tree());

        stop();
    });

    it('carries the whole write contract while creating, including the object type', () => {
        const { definition, stop } = scoped(null);

        expect(Object.keys(definition.savePayload()).sort()).toEqual(
            [
                'aggregation_field_key',
                'aggregation_type',
                'chart_type',
                'description',
                'execution_mode',
                'filter_definition',
                'group_by_bucket',
                'group_by_field_key',
                'name',
                'object_type_id',
                'series_field_key',
            ].sort(),
        );

        stop();
    });

    it('drops the object type from the write contract while editing, because it is immutable', () => {
        const { definition, stop } = scoped(reportRow());

        expect(Object.keys(definition.savePayload())).not.toContain(
            'object_type_id',
        );
        expect(definition.savePayload().name).toBe('Pipeline');

        stop();
    });

    it('sends the object type and exactly the six definition keys to the preview', () => {
        const { definition, stop } = scoped(reportRow());

        expect(Object.keys(definition.previewPayload()).sort()).toEqual(
            [
                'aggregation_field_key',
                'aggregation_type',
                'filter_definition',
                'group_by_bucket',
                'group_by_field_key',
                'object_type_id',
                'series_field_key',
            ].sort(),
        );

        stop();
    });

    it('never sends a report id, a chart type or an execution mode to the preview', () => {
        const { definition, stop } = scoped(reportRow());

        const keys = Object.keys(definition.previewPayload());

        expect(keys).toContain('object_type_id');
        expect(keys).toContain('aggregation_type');
        expect(keys).not.toContain('report_id');
        expect(keys).not.toContain('chart_type');
        expect(keys).not.toContain('execution_mode');

        stop();
    });
});

describe('useReportDefinition — snapshot', () => {
    it('treats an empty tree and a missing tree as the very same state', () => {
        const { definition, stop } = scoped(
            reportRow({ filter_definition: [] }),
        );

        const untouched = JSON.stringify(definition.snapshot());

        definition.filterTree.value = tree();

        expect(JSON.stringify(definition.snapshot())).toBe(untouched);

        definition.name.value = 'Renamed';

        expect(JSON.stringify(definition.snapshot())).not.toBe(untouched);

        stop();
    });

    it('registers a change in every single definition key', () => {
        const mutations: Array<[string, (definition: Definition) => void]> = [
            ['name', (definition) => (definition.name.value = 'Renamed')],
            [
                'description',
                (definition) => (definition.description.value = 'Anders'),
            ],
            [
                'objectTypeId',
                (definition) => (definition.objectTypeId.value = SECOND_TYPE),
            ],
            [
                'aggregationType',
                (definition) => (definition.aggregationType.value = 'count'),
            ],
            [
                'aggregationFieldKey',
                (definition) =>
                    (definition.aggregationFieldKey.value = 'other_amount'),
            ],
            [
                'groupByFieldKey',
                (definition) => (definition.groupByFieldKey.value = 'stage'),
            ],
            [
                'groupByBucket',
                (definition) => (definition.groupByBucket.value = 'year'),
            ],
            [
                'seriesFieldKey',
                (definition) => (definition.seriesFieldKey.value = 'owner_id'),
            ],
            [
                'chartType',
                (definition) => (definition.chartType.value = 'donut'),
            ],
            [
                'executionMode',
                (definition) => (definition.executionMode.value = 'definer'),
            ],
            [
                'filterTree',
                (definition) =>
                    (definition.filterTree.value = tree([
                        { field: 'stage', operator: 'equals', value: 'lost' },
                    ])),
            ],
        ];

        expect(mutations).toHaveLength(11);

        mutations.forEach(([key, mutate]) => {
            const { changed, stop } = snapshotChangesAfter(mutate);

            expect(changed, `changing ${key} must mark the form dirty`).toBe(
                true,
            );

            stop();
        });
    });
});
