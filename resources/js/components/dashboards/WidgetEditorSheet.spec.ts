import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import WidgetEditorSheet from '@/components/dashboards/WidgetEditorSheet.vue';
import ReportDefinitionFields from '@/components/reports/ReportDefinitionFields.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { REPORT_EXECUTION_MODE } from '@/lib/statusMaps';
import { segmentPrefill } from '@/routes/reports';
import { comboboxStubs, selectStubs } from '@/tests/selectStubs';
import type { DashboardWidgetMeta } from '@/types/dashboards';
import {
    WIDGET_EDITOR_CREATE_TITLE,
    WIDGET_EDITOR_EDIT_TITLE,
    WIDGET_SEGMENT_PREFILL_ERROR,
} from '@/types/dashboards';
import type { FieldDefinition } from '@/types/fields';
import type { SelectOption } from '@/types/ui';
import { setUrlDefaults } from '@/wayfinder';

const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: inertia.visit },
    usePage: () => ({
        url: '/nubos/dashboards/01DASHBOARD00000000000001',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

vi.mock('vue-sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const OBJECT_TYPE_ID = '01OBJECTTYPE000000000001';

const OTHER_OBJECT_TYPE_ID = '01OBJECTTYPE000000000002';

const REPORT_ID = '01REPORT0000000000000001';

const ACTIVE_TEAM = 'nubos';

const ENGLISH_WORDS = /\b(the|this|that|your|report|please|select)\b/i;

const DEFINITION_KEYS = [
    'aggregation_field_key',
    'aggregation_type',
    'filter_definition',
    'group_by_bucket',
    'group_by_field_key',
    'series_field_key',
];

const GOAL_OPTIONS: SelectOption[] = [
    {
        value: '01GOAL00000000000000001A',
        label: 'Quartalsziel',
        description: 'Abschlüsse',
    },
];

const REPORT_OPTIONS: SelectOption[] = [
    { value: REPORT_ID, label: 'Umsatzauswertung' },
    { value: '01REPORT0000000000000002', label: 'Abschlussquote' },
];

const OBJECT_TYPE_OPTIONS: SelectOption[] = [
    { value: OBJECT_TYPE_ID, label: 'Unternehmen' },
    { value: OTHER_OBJECT_TYPE_ID, label: 'Personen' },
];

const SEGMENT_ID = '01SEGMENT000000000000001';

const SEGMENT_OPTIONS: SelectOption[] = [
    { value: SEGMENT_ID, label: 'Aktive Kunden' },
    { value: '01SEGMENT000000000000002', label: 'Verlorene Kunden' },
];

const FIELDS: FieldDefinition[] = [
    {
        key: 'amount',
        field_type: 'money',
        label: 'Betrag',
        is_required: false,
    },
    {
        key: 'closed_at',
        field_type: 'date',
        label: 'Abschlussdatum',
        is_required: false,
    },
    {
        key: 'stage',
        field_type: 'single_select',
        label: 'Phase',
        is_required: false,
    },
];

const SEGMENT_FILTER: FilterGroupNode = {
    combinator: 'and',
    conditions: [{ field: 'stage', operator: 'equals', value: 'won' }],
};

type Wrapper = VueWrapper;

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

const passthrough = { template: '<div><slot /></div>' };

const FilterBuilderStub = {
    name: 'FilterBuilderStub',
    props: ['fields', 'modelValue', 'showActions'],
    template: '<div data-filter-builder />',
};

const stubs = {
    ...selectStubs,
    ...comboboxStubs,
    Sheet: passthrough,
    SheetContent: passthrough,
    SheetHeader: passthrough,
    SheetFooter: passthrough,
    SheetTitle: { template: '<h2><slot /></h2>' },
    SheetDescription: { template: '<p><slot /></p>' },
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
    FilterBuilder: FilterBuilderStub,
};

interface MountOptions {
    widget?: DashboardWidgetMeta | null;
    reportOptions?: SelectOption[];
    goalOptions?: SelectOption[];
    fields?: FieldDefinition[];
    segments?: SelectOption[];
    errors?: Record<string, string>;
    processing?: boolean;
}

function mountSheet(options: MountOptions = {}): Wrapper {
    return mount(WidgetEditorSheet, {
        props: {
            dashboardId: DASHBOARD_ID,
            widget: options.widget ?? null,
            reportOptions: options.reportOptions ?? REPORT_OPTIONS,
            goalOptions: options.goalOptions ?? GOAL_OPTIONS,
            objectTypeOptions: OBJECT_TYPE_OPTIONS,
            fieldsByType: {
                [OBJECT_TYPE_ID]: options.fields ?? [],
                [OTHER_OBJECT_TYPE_ID]: options.fields ?? [],
            },
            linkedFieldsByType: {
                [OBJECT_TYPE_ID]: [],
                [OTHER_OBJECT_TYPE_ID]: [],
            },
            segmentsByType: {
                [OBJECT_TYPE_ID]: options.segments ?? [],
                [OTHER_OBJECT_TYPE_ID]: [],
            },
            errors: options.errors ?? {},
            processing: options.processing ?? false,
        },
        global: { stubs },
    });
}

function reportWidget(): DashboardWidgetMeta {
    return {
        id: '01WIDGET000000000000000A',
        dashboard_id: DASHBOARD_ID,
        report_id: REPORT_ID,
        title: 'Umsatz je Region',
        chart_type: 'bar',
        goal_id: null,
        definition: null,
        position: 1,
        column_span: 2,
        updated_at: '2026-08-10T11:00:00+00:00',
    };
}

function adhocWidget(): DashboardWidgetMeta {
    return {
        ...reportWidget(),
        report_id: null,
        definition: {
            object_type_id: OBJECT_TYPE_ID,
            filter_definition: SEGMENT_FILTER,
            aggregation_type: 'sum',
            aggregation_field_key: 'amount',
            group_by_field_key: 'closed_at',
            group_by_bucket: 'week',
            series_field_key: 'stage',
        },
    };
}

function selectValue(wrapper: Wrapper, hook: string): string {
    return (wrapper.get(hook).element as HTMLSelectElement).value;
}

function inputValue(wrapper: Wrapper, hook: string): string {
    return (wrapper.get(hook).element as HTMLInputElement).value;
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function pathOf(url: string): string {
    return new URL(url, 'http://localhost').pathname;
}

function isReportBranch(wrapper: Wrapper): boolean {
    return wrapper.find('[data-widget-report-select]').exists();
}

function isAdhocBranch(wrapper: Wrapper): boolean {
    return wrapper.find('[data-widget-object-type-select]').exists();
}

function isGoalBranch(wrapper: Wrapper): boolean {
    return (
        wrapper.find('[data-widget-goal-select]').exists() ||
        wrapper.find('[data-widget-goal-empty]').exists()
    );
}

async function chooseSource(
    wrapper: Wrapper,
    kind: 'report' | 'adhoc' | 'goal',
): Promise<string> {
    const select = wrapper.get('[data-widget-source-select]');
    const values = select
        .findAll('option')
        .map((option) => String(option.attributes('value')));

    expect(values.length).toBeGreaterThanOrEqual(2);

    for (const value of values) {
        await select.setValue(value);
        await nextTick();

        if (kind === 'report' && isReportBranch(wrapper)) {
            return value;
        }

        if (kind === 'adhoc' && isAdhocBranch(wrapper)) {
            return value;
        }

        if (kind === 'goal' && isGoalBranch(wrapper)) {
            return value;
        }
    }

    throw new Error(`no source option renders the ${kind} branch`);
}

function optionValuesOf(wrapper: Wrapper, hook: string): string[] {
    return wrapper
        .get(hook)
        .findAll('option')
        .map((option) => String(option.attributes('value')));
}

async function pick(
    wrapper: Wrapper,
    hook: string,
    value: string,
): Promise<void> {
    await wrapper.get(hook).setValue(value);
    await nextTick();
}

async function save(wrapper: Wrapper): Promise<void> {
    await wrapper.get('[data-form-save]').trigger('click');
    await nextTick();
}

function submittedPayload(wrapper: Wrapper): Record<string, unknown> {
    const emitted = wrapper.emitted('submit');

    if (emitted === undefined) {
        throw new Error('the editor never submitted');
    }

    return emitted[emitted.length - 1][0] as Record<string, unknown>;
}

function actionButtons(wrapper: Wrapper): DOMWrapper<Element>[] {
    return wrapper.get('[data-form-actions]').findAll('button');
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: ACTIVE_TEAM });
    inertia.visit.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('WidgetEditorSheet — reopening a tile that already exists', () => {
    it('hydrates a report bound tile with everything it carries', () => {
        const created = mountSheet();

        expect(created.get('h2').text()).toBe(WIDGET_EDITOR_CREATE_TITLE);

        const wrapper = mountSheet({ widget: reportWidget() });

        expect(wrapper.get('h2').text()).toBe(WIDGET_EDITOR_EDIT_TITLE);
        expect(isReportBranch(wrapper)).toBe(true);
        expect(isAdhocBranch(wrapper)).toBe(false);
        expect(selectValue(wrapper, '[data-widget-report-select]')).toBe(
            REPORT_ID,
        );
        expect(inputValue(wrapper, '[data-widget-title-input]')).toBe(
            'Umsatz je Region',
        );
        expect(selectValue(wrapper, '[data-widget-editor-span]')).toBe('2');
        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeDefined();
    });

    it('starts a tile with its own definition in the ad-hoc branch and gives every value back unchanged', async () => {
        const wrapper = mountSheet({
            widget: adhocWidget(),
            fields: FIELDS,
        });

        expect(isAdhocBranch(wrapper)).toBe(true);
        expect(isReportBranch(wrapper)).toBe(false);
        expect(selectValue(wrapper, '[data-widget-object-type-select]')).toBe(
            OBJECT_TYPE_ID,
        );

        const definition = wrapper.findComponent(ReportDefinitionFields);

        expect(definition.props('aggregationType')).toBe('sum');
        expect(definition.props('aggregationFieldKey')).toBe('amount');
        expect(definition.props('groupByFieldKey')).toBe('closed_at');
        expect(definition.props('groupByBucket')).toBe('week');
        expect(definition.props('seriesFieldKey')).toBe('stage');
        expect(definition.props('chartType')).toBe('bar');
        expect(
            wrapper.findComponent(FilterBuilderStub).props('modelValue'),
        ).toEqual(SEGMENT_FILTER);
        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeDefined();

        await wrapper.get('[data-widget-title-input]').setValue('Neuer Titel');
        await save(wrapper);

        const payload = submittedPayload(wrapper);

        expect(payload.object_type_id).toBe(OBJECT_TYPE_ID);
        expect(payload.aggregation_type).toBe('sum');
        expect(payload.aggregation_field_key).toBe('amount');
        expect(payload.group_by_field_key).toBe('closed_at');
        expect(payload.group_by_bucket).toBe('week');
        expect(payload.series_field_key).toBe('stage');
        expect(payload.filter_definition).toEqual(SEGMENT_FILTER);
        expect(payload.chart_type).toBe('bar');
        expect(payload.column_span).toBe(2);
        expect(payload.title).toBe('Neuer Titel');
        expect(Object.keys(payload)).not.toContain('report_id');
    });
});

describe('WidgetEditorSheet — prefilling the restriction from a segment', () => {
    it('copies the filter of the chosen segment once into its own definition', async () => {
        const fetchMock = vi.fn((url: string, init?: RequestInit) => {
            expect(init?.method ?? 'GET').toBe('GET');

            return Promise.resolve(
                jsonResponse(200, {
                    data: {
                        object_type_id: OBJECT_TYPE_ID,
                        filter_definition: SEGMENT_FILTER,
                    },
                }),
            );
        });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountSheet({
            fields: FIELDS,
            segments: SEGMENT_OPTIONS,
        });

        await chooseSource(wrapper, 'adhoc');
        await pick(wrapper, '[data-widget-object-type-select]', OBJECT_TYPE_ID);

        expect(optionValuesOf(wrapper, '[data-widget-segment-select]')).toEqual(
            SEGMENT_OPTIONS.map((option) => option.value),
        );
        expect(
            wrapper.findComponent(FilterBuilderStub).props('modelValue'),
        ).toBeUndefined();

        await pick(wrapper, '[data-widget-segment-select]', SEGMENT_ID);
        await wrapper.get('[data-widget-segment-apply]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(pathOf(String(fetchMock.mock.calls[0][0]))).toBe(
            pathOf(segmentPrefill.url({ segment: SEGMENT_ID })),
        );
        expect(
            wrapper.findComponent(FilterBuilderStub).props('modelValue'),
        ).toEqual(SEGMENT_FILTER);
        expect(wrapper.find('[data-widget-segment-error]').exists()).toBe(
            false,
        );

        await save(wrapper);

        expect(submittedPayload(wrapper).filter_definition).toEqual(
            SEGMENT_FILTER,
        );
    });

    it('keeps the restriction it already had and says so when the copy fails', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(500, { message: 'refused' })),
        );

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountSheet({
            widget: adhocWidget(),
            fields: FIELDS,
            segments: SEGMENT_OPTIONS,
        });

        expect(wrapper.find('[data-widget-segment-error]').exists()).toBe(
            false,
        );
        expect(
            wrapper.findComponent(FilterBuilderStub).props('modelValue'),
        ).toEqual(SEGMENT_FILTER);

        await pick(wrapper, '[data-widget-segment-select]', SEGMENT_ID);
        await wrapper.get('[data-widget-segment-apply]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-widget-segment-error]').text()).toBe(
            WIDGET_SEGMENT_PREFILL_ERROR,
        );
        expect(
            wrapper.findComponent(FilterBuilderStub).props('modelValue'),
        ).toEqual(SEGMENT_FILTER);

        await wrapper.get('[data-widget-title-input]').setValue('Neuer Titel');
        await save(wrapper);

        expect(submittedPayload(wrapper).filter_definition).toEqual(
            SEGMENT_FILTER,
        );
    });

    it('asks the server only once while a copy is still running', async () => {
        let release: (value: Response) => void = () => undefined;
        const pending = new Promise<Response>((resolve) => {
            release = resolve;
        });
        const fetchMock = vi.fn(() => pending);

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountSheet({
            widget: adhocWidget(),
            fields: FIELDS,
            segments: SEGMENT_OPTIONS,
        });

        await pick(wrapper, '[data-widget-segment-select]', SEGMENT_ID);

        const apply = wrapper.get('[data-widget-segment-apply]');

        expect(apply.attributes('disabled')).toBeUndefined();

        void apply.trigger('click');
        await nextTick();

        expect(apply.attributes('disabled')).toBeDefined();

        await apply.trigger('click');

        expect(fetchMock).toHaveBeenCalledTimes(1);

        release(
            jsonResponse(200, {
                data: {
                    object_type_id: OBJECT_TYPE_ID,
                    filter_definition: SEGMENT_FILTER,
                },
            }),
        );
        await flushPromises();

        expect(apply.attributes('disabled')).toBeUndefined();
        expect(wrapper.find('[data-widget-segment-error]').exists()).toBe(
            false,
        );
    });

    it('offers nothing to copy from while the tile shows a saved report', async () => {
        const wrapper = mountSheet({
            fields: FIELDS,
            segments: SEGMENT_OPTIONS,
        });

        await chooseSource(wrapper, 'report');

        expect(wrapper.find('[data-widget-report-select]').exists()).toBe(true);
        expect(wrapper.find('[data-widget-segment-select]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-widget-segment-apply]').exists()).toBe(
            false,
        );
    });
});

describe('WidgetEditorSheet — a widget that shows a saved report', () => {
    it('offers exactly the reports the server sent and submits the chosen one', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'report');

        expect(optionValuesOf(wrapper, '[data-widget-report-select]')).toEqual(
            REPORT_OPTIONS.map((option) => option.value),
        );

        await pick(wrapper, '[data-widget-report-select]', REPORT_ID);
        await save(wrapper);

        const payload = submittedPayload(wrapper);

        expect(payload.report_id).toBe(REPORT_ID);
        expect(payload.chart_type).toBeDefined();
        expect(Object.keys(payload)).not.toContain('object_type_id');
    });

    it('explains in German that no report exists yet instead of an empty picker', async () => {
        const filled = mountSheet();
        const reportSource = await chooseSource(filled, 'report');

        expect(filled.find('[data-widget-report-select]').exists()).toBe(true);
        expect(filled.find('[data-widget-report-empty]').exists()).toBe(false);

        const empty = mountSheet({ reportOptions: [] });

        await empty.get('[data-widget-source-select]').setValue(reportSource);
        await nextTick();

        expect(empty.find('[data-widget-report-select]').exists()).toBe(false);

        const explanation = empty.get('[data-widget-report-empty]').text();

        expect(explanation.length).toBeGreaterThan(20);
        expect(explanation).not.toMatch(ENGLISH_WORDS);
    });
});

describe('WidgetEditorSheet — a widget that carries its own definition', () => {
    it('reuses the shared definition editor and submits the definition keys', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'adhoc');

        expect(wrapper.findAllComponents(ReportDefinitionFields)).toHaveLength(
            1,
        );

        await pick(wrapper, '[data-widget-object-type-select]', OBJECT_TYPE_ID);
        await save(wrapper);

        const payload = submittedPayload(wrapper);
        const keys = Object.keys(payload);

        expect(payload.object_type_id).toBe(OBJECT_TYPE_ID);
        expect(payload.chart_type).toBeDefined();

        DEFINITION_KEYS.forEach((key) => {
            expect(keys).toContain(key);
        });

        expect(keys).not.toContain('report_id');
        expect(keys).not.toContain('execution_mode');
    });

    it('drops the field keys of the object type it just left behind', async () => {
        const wrapper = mountSheet({ fields: FIELDS });

        await chooseSource(wrapper, 'adhoc');
        await pick(wrapper, '[data-widget-object-type-select]', OBJECT_TYPE_ID);

        const definition = wrapper.findComponent(ReportDefinitionFields);

        definition.vm.$emit('update:aggregationType', 'sum');
        definition.vm.$emit('update:aggregationFieldKey', 'amount');
        definition.vm.$emit('update:groupByFieldKey', 'closed_at');
        definition.vm.$emit('update:groupByBucket', 'week');
        definition.vm.$emit('update:seriesFieldKey', 'stage');
        await nextTick();

        expect(definition.props('aggregationFieldKey')).toBe('amount');
        expect(definition.props('groupByFieldKey')).toBe('closed_at');
        expect(definition.props('groupByBucket')).toBe('week');
        expect(definition.props('seriesFieldKey')).toBe('stage');

        await pick(
            wrapper,
            '[data-widget-object-type-select]',
            OTHER_OBJECT_TYPE_ID,
        );

        const switched = wrapper.findComponent(ReportDefinitionFields);

        expect(switched.props('aggregationFieldKey')).toBeNull();
        expect(switched.props('groupByFieldKey')).toBeNull();
        expect(switched.props('groupByBucket')).toBeNull();
        expect(switched.props('seriesFieldKey')).toBeNull();

        await save(wrapper);

        const payload = submittedPayload(wrapper);

        expect(payload.object_type_id).toBe(OTHER_OBJECT_TYPE_ID);
        expect(payload.aggregation_field_key).toBeNull();
        expect(payload.group_by_field_key).toBeNull();
        expect(payload.group_by_bucket).toBeNull();
        expect(payload.series_field_key).toBeNull();
        expect(payload.filter_definition).toBeNull();
    });

    it('never offers an execution mode of its own next to the definition editor', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'adhoc');

        expect(wrapper.findComponent(ReportDefinitionFields).exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-report-execution-mode]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).not.toContain(
            REPORT_EXECUTION_MODE.definer.label,
        );
    });
});

describe('WidgetEditorSheet — switching the source', () => {
    it('drops the report reference on the way to an own definition', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'report');
        await pick(wrapper, '[data-widget-report-select]', REPORT_ID);
        await chooseSource(wrapper, 'adhoc');
        await pick(wrapper, '[data-widget-object-type-select]', OBJECT_TYPE_ID);
        await save(wrapper);

        const payload = submittedPayload(wrapper);

        expect(Object.keys(payload)).not.toContain('report_id');
        expect(payload.object_type_id).toBe(OBJECT_TYPE_ID);
    });

    it('drops the object type on the way back to a saved report', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'adhoc');
        await pick(wrapper, '[data-widget-object-type-select]', OBJECT_TYPE_ID);
        await chooseSource(wrapper, 'report');
        await pick(wrapper, '[data-widget-report-select]', REPORT_ID);
        await save(wrapper);

        const payload = submittedPayload(wrapper);

        expect(Object.keys(payload)).not.toContain('object_type_id');
        expect(payload.report_id).toBe(REPORT_ID);
    });
});

describe('WidgetEditorSheet — the shared form contract', () => {
    it('submits through the shared save button labelled Speichern', async () => {
        const wrapper = mountSheet();

        expect(wrapper.get('[data-form-save]').text()).toBe('Speichern');
        expect(wrapper.find('[data-create-button]').exists()).toBe(false);

        const buttons = actionButtons(wrapper);

        expect(buttons.length).toBeGreaterThanOrEqual(2);
        expect(buttons[0].attributes('data-form-cancel')).toBeDefined();
        expect(
            buttons[buttons.length - 1].attributes('data-form-save'),
        ).toBeDefined();
    });

    it('keeps saving disabled until something actually changed', async () => {
        const wrapper = mountSheet();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeDefined();

        await chooseSource(wrapper, 'report');
        await pick(wrapper, '[data-widget-report-select]', REPORT_ID);

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('emits exactly one submit per click on save', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'report');
        await pick(wrapper, '[data-widget-report-select]', REPORT_ID);
        await save(wrapper);

        expect(wrapper.emitted('submit')).toHaveLength(1);
    });

    it('asks before dropping pending changes and closes only after the confirmation', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'report');
        await pick(wrapper, '[data-widget-report-select]', REPORT_ID);

        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(wrapper.findComponent(UnsavedChangesDialog).props('open')).toBe(
            true,
        );
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(inertia.visit).not.toHaveBeenCalled();

        wrapper.findComponent(UnsavedChangesDialog).vm.$emit('confirm');
        await nextTick();

        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('closes right away while nothing changed', async () => {
        const wrapper = mountSheet();

        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(wrapper.findComponent(UnsavedChangesDialog).props('open')).toBe(
            false,
        );
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('renders a bound message for every key the write path can reject', () => {
        const wrapper = mountSheet({
            errors: {
                report_id: 'FEHLER_REPORT',
                chart_type: 'FEHLER_CHART',
                column_span: 'FEHLER_SPAN',
            },
        });

        expect(wrapper.text()).toContain('FEHLER_REPORT');
        expect(wrapper.text()).toContain('FEHLER_CHART');
        expect(wrapper.text()).toContain('FEHLER_SPAN');
    });

    it('names itself for screen readers and carries its own hooks', () => {
        const wrapper = mountSheet();

        expect(wrapper.find('[data-widget-editor]').exists()).toBe(true);
        expect(wrapper.get('h2').text().length).toBeGreaterThan(3);
        expect(wrapper.find('[data-widget-title-input]').exists()).toBe(true);
        expect(wrapper.find('[data-widget-editor-span]').exists()).toBe(true);
        expect(wrapper.find('[data-widget-span-select]').exists()).toBe(false);
    });
});

describe('WidgetEditorSheet — a tile that shows a goal', () => {
    it('offers the goals the server sent and never a free text field for an id', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'goal');

        expect(optionValuesOf(wrapper, '[data-widget-goal-select]')).toEqual(
            GOAL_OPTIONS.map((option) => option.value),
        );
        expect(wrapper.find('input[name="goal_id"]').exists()).toBe(false);
    });

    it('submits the goal alone, without a chart type and without a definition', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'goal');
        await pick(wrapper, '[data-widget-goal-select]', GOAL_OPTIONS[0].value);
        await save(wrapper);

        const payload = submittedPayload(wrapper);

        expect(payload.goal_id).toBe(GOAL_OPTIONS[0].value);
        expect(Object.keys(payload)).not.toContain('report_id');
        expect(Object.keys(payload)).not.toContain('object_type_id');
        expect(Object.keys(payload)).not.toContain('chart_type');
    });

    it('drops the goal again when the tile switches back to a report', async () => {
        const wrapper = mountSheet();

        await chooseSource(wrapper, 'goal');
        await pick(wrapper, '[data-widget-goal-select]', GOAL_OPTIONS[0].value);

        await chooseSource(wrapper, 'report');
        await pick(wrapper, '[data-widget-report-select]', REPORT_ID);
        await save(wrapper);

        const payload = submittedPayload(wrapper);

        expect(payload.report_id).toBe(REPORT_ID);
        expect(Object.keys(payload)).not.toContain('goal_id');
    });

    it('explains in German that no goal exists yet instead of an empty picker', async () => {
        const empty = mountSheet({ goalOptions: [] });

        await chooseSource(empty, 'goal');

        expect(empty.find('[data-widget-goal-select]').exists()).toBe(false);

        const explanation = empty.get('[data-widget-goal-empty]').text();

        expect(explanation.length).toBeGreaterThan(20);
        expect(explanation).toMatch(/Ziel/);
    });

    it('opens on the goal branch when the tile it edits is bound to a goal', () => {
        const wrapper = mountSheet({
            widget: {
                ...reportWidget(),
                report_id: null,
                goal_id: GOAL_OPTIONS[0].value,
                chart_type: null,
                definition: null,
            },
        });

        expect(isGoalBranch(wrapper)).toBe(true);
        expect(isReportBranch(wrapper)).toBe(false);
    });
});
