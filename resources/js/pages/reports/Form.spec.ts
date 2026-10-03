import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import { Checkbox } from '@/components/ui/checkbox';
import Form from '@/pages/reports/Form.vue';
import { comboboxStubs, selectStubs } from '@/tests/selectStubs';
import type { FieldDefinition } from '@/types/fields';
import type { ReportListRow } from '@/types/reports';
import type { SelectOption } from '@/types/ui';

interface FormState {
    errors: Record<string, string>;
    processing: boolean;
}

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    visit: vi.fn(),
    transform: {
        apply: null as
            | null
            | ((data: Record<string, unknown>) => Record<string, unknown>),
    },
    form: null as null | FormState,
    page: {
        url: '/nubos/reports/create',
        props: {
            auth: {
                user: null,
                can: {} as Record<string, boolean>,
                authority: null as string | null,
            },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => {
    const state = reactive({
        errors: {} as Record<string, string>,
        processing: false,
        transform(
            fn: (data: Record<string, unknown>) => Record<string, unknown>,
        ) {
            inertia.transform.apply = fn;

            return state;
        },
        post: inertia.post,
        put: inertia.put,
    });

    inertia.form = state;

    return {
        Head: { template: '<head-stub><slot /></head-stub>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (initial: Record<string, unknown>) =>
            Object.assign(state, initial),
        router: {
            on: vi.fn(() => vi.fn()),
            visit: inertia.visit,
            post: inertia.post,
        },
        usePage: () => inertia.page,
    };
});

const PRIMARY_TYPE = '01OBJECTTYPE000000000001';
const SECOND_TYPE = '01OBJECTTYPE000000000002';

const ERROR_KEYS = [
    'name',
    'description',
    'object_type_id',
    'filter_definition',
    'aggregation_type',
    'aggregation_field_key',
    'group_by_field_key',
    'group_by_bucket',
    'series_field_key',
    'chart_type',
    'execution_mode',
];

const FilterBuilderStub = defineComponent({
    name: 'FilterBuilderStub',
    props: {
        fields: { type: Array, default: () => [] },
        modelValue: { type: Object, default: undefined },
        showActions: { type: Boolean, default: true },
    },
    emits: ['update:modelValue', 'apply', 'reset'],
    setup(props, { emit }) {
        emit(
            'update:modelValue',
            props.modelValue ?? { combinator: 'and', conditions: [] },
        );

        return () => h('div', { 'data-filter-builder-stub': true });
    },
});

const ReportPreviewStub = defineComponent({
    name: 'ReportPreviewStub',
    props: {
        title: { type: String, default: '' },
        presentation: { type: String, default: '' },
        bucket: { type: String, default: null },
        payload: { type: Function, default: () => ({}) },
        drillDownSource: { type: Object, default: null },
    },
    setup: () => () => h('div', { 'data-report-preview-stub': true }),
});

function field(overrides: Partial<FieldDefinition> = {}): FieldDefinition {
    return {
        key: 'stage',
        field_type: 'text_short',
        label: 'Phase',
        is_required: false,
        is_sortable: true,
        is_filterable: true,
        is_default_column: false,
        list_position: 1,
        config: null,
        validation_rules: null,
        default_value: null,
        ...overrides,
    };
}

const FIELDS: FieldDefinition[] = [
    field(),
    field({ key: 'amount', field_type: 'money', label: 'Betrag' }),
    field({ key: 'closed_at', field_type: 'date', label: 'Abschluss' }),
    field({ key: 'owner_id', field_type: 'single_select', label: 'Besitzer' }),
];

const LINKED_FIELDS: FieldDefinition[] = [
    field({
        key: 'contacts.email',
        field_type: 'email',
        label: 'Kontakte › E-Mail',
        is_filterable: false,
        is_sortable: false,
    }),
];

const OBJECT_TYPE_OPTIONS: SelectOption[] = [
    { value: PRIMARY_TYPE, label: 'Deals' },
    { value: SECOND_TYPE, label: 'Contacts' },
];

const SEGMENT_OPTIONS: SelectOption[] = [
    { value: '01SEGMENT00000000000001', label: 'Offene Deals' },
];

function reportRow(overrides: Partial<ReportListRow> = {}): ReportListRow {
    return {
        id: '01REPORT0000000000000001',
        name: 'Pipeline',
        description: 'Nach Phase',
        object_type_id: PRIMARY_TYPE,
        object_type: { id: PRIMARY_TYPE, slug: 'deals', name: 'Deals' },
        filter_definition: [],
        aggregation_type: 'count',
        aggregation_field_key: null,
        group_by_field_key: 'closed_at',
        group_by_bucket: 'month',
        series_field_key: null,
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

function mountForm(props: Record<string, unknown> = {}): VueWrapper {
    return mount(Form, {
        props: {
            mode: 'create',
            report: null,
            objectTypeOptions: OBJECT_TYPE_OPTIONS,
            fieldsByType: {
                [PRIMARY_TYPE]: FIELDS,
                [SECOND_TYPE]: [],
            },
            linkedFieldsByType: {
                [PRIMARY_TYPE]: LINKED_FIELDS,
                [SECOND_TYPE]: [],
            },
            segmentsByType: {
                [PRIMARY_TYPE]: SEGMENT_OPTIONS,
                [SECOND_TYPE]: [],
            },
            ...props,
        },
        global: {
            stubs: {
                ...selectStubs,
                ...comboboxStubs,
                FilterBuilder: FilterBuilderStub,
                ReportPreview: ReportPreviewStub,
            },
        },
    });
}

function mountEdit(overrides: Partial<ReportListRow> = {}): VueWrapper {
    return mountForm({ mode: 'edit', report: reportRow(overrides) });
}

function slot(
    wrapper: VueWrapper,
    name: string,
): Omit<DOMWrapper<Element>, 'exists'> {
    return wrapper.get(`[data-report-field="${name}"]`);
}

function picker(wrapper: VueWrapper, name: string): DOMWrapper<Element> {
    const control = slot(wrapper, name).find('select');

    if (!control.exists()) {
        throw new Error(`Field "${name}" renders no control`);
    }

    return control;
}

function isSaveDisabled(wrapper: VueWrapper): boolean {
    return wrapper.get('[data-form-save]').attributes('disabled') !== undefined;
}

function formState(): FormState {
    if (inertia.form === null) {
        throw new Error('The Inertia form was never created');
    }

    return inertia.form;
}

function submittedPayload(): Record<string, unknown> {
    const apply = inertia.transform.apply;

    if (apply === null) {
        throw new Error(
            'The form never transformed its payload before sending',
        );
    }

    return apply({});
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.visit.mockReset();
    inertia.transform.apply = null;
    inertia.page.props.auth.authority = null;
    formState().errors = {};
    formState().processing = false;
});

describe('reports/Form — unsaved changes', () => {
    it('keeps saving disabled until something actually changed', () => {
        expect(isSaveDisabled(mountForm())).toBe(true);
    });

    it('never counts the tree the builder emits on mount as a change', async () => {
        const wrapper = mountEdit();

        await nextTick();

        expect(isSaveDisabled(wrapper)).toBe(true);
    });

    it('counts an edited filter tree as a change', async () => {
        const wrapper = mountEdit();

        wrapper.findComponent(FilterBuilderStub).vm.$emit('update:modelValue', {
            combinator: 'and',
            conditions: [{ field: 'stage', operator: 'equals', value: 'won' }],
        });
        await nextTick();

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the name as a change', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#report-name').setValue('Pipeline 2026');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the description as a change', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#report-description').setValue('Neue Beschreibung');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the object type as a change while creating', async () => {
        const wrapper = mountForm();

        await picker(wrapper, 'object-type').setValue(SECOND_TYPE);

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the aggregate as a change', async () => {
        const wrapper = mountEdit();

        await picker(wrapper, 'aggregation-type').setValue('sum');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the aggregate field as a change', async () => {
        const wrapper = mountEdit();

        await picker(wrapper, 'aggregation-field').setValue('amount');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the grouping field as a change', async () => {
        const wrapper = mountEdit();

        await picker(wrapper, 'group-by-field').setValue('stage');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the time bucket as a change', async () => {
        const wrapper = mountEdit();

        await picker(wrapper, 'group-by-bucket').setValue('year');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the series field as a change', async () => {
        const wrapper = mountEdit();

        await picker(wrapper, 'series-field').setValue('stage');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the presentation as a change', async () => {
        const wrapper = mountEdit();

        await picker(wrapper, 'chart-type').setValue('donut');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the execution mode as a change', async () => {
        inertia.page.props.auth.authority = 'super_admin';

        const wrapper = mountEdit();

        wrapper.findComponent(Checkbox).vm.$emit('update:modelValue', true);
        await nextTick();

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#report-name').setValue('Pipeline 2026');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
        expect(inertia.visit).not.toHaveBeenCalled();
    });
});

describe('reports/Form — action row', () => {
    it('submits through the shared save button, also while creating', () => {
        const wrapper = mountForm();

        expect(wrapper.get('[data-form-save]').text()).toBe('Speichern');
        expect(wrapper.find('[data-form-actions]').exists()).toBe(true);
        expect(wrapper.find('[data-create-button]').exists()).toBe(false);
    });

    it('renders no page level back link', () => {
        expect(mountForm().findAll('a')).toHaveLength(0);
    });

    it('posts to the store route while creating', async () => {
        const wrapper = mountForm();

        await wrapper.get('#report-name').setValue('Neue Auswertung');
        await wrapper.get('form').trigger('submit');

        expect(inertia.post.mock.calls[0][0]).toBe(
            ReportsController.store.url(),
        );
        expect(submittedPayload().object_type_id).toBe(PRIMARY_TYPE);
        expect(submittedPayload().name).toBe('Neue Auswertung');
    });

    it('puts to the update route while editing and never resends the object type', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#report-name').setValue('Pipeline 2026');
        await wrapper.get('form').trigger('submit');

        expect(inertia.put.mock.calls[0][0]).toBe(
            ReportsController.update.url({ report: reportRow().id }),
        );
        expect(Object.keys(submittedPayload())).not.toContain('object_type_id');
    });
});

describe('reports/Form — definer switch', () => {
    it('hides the switch entirely from an actor without escalated authority', () => {
        const wrapper = mountEdit();

        expect(wrapper.find('[data-report-execution-mode]').exists()).toBe(
            false,
        );
    });

    it('offers the switch to an escalated authority', () => {
        inertia.page.props.auth.authority = 'super_admin';

        const wrapper = mountEdit();

        expect(wrapper.find('[data-report-execution-mode]').exists()).toBe(
            true,
        );
        expect(wrapper.findComponent(Checkbox).exists()).toBe(true);
    });

    it('leaves the execution mode out of the payload of a plain actor', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#report-name').setValue('Pipeline 2026');
        await wrapper.get('form').trigger('submit');

        expect(Object.keys(submittedPayload())).not.toContain('execution_mode');
    });

    it('sends the execution mode for an escalated authority', async () => {
        inertia.page.props.auth.authority = 'super_admin';

        const wrapper = mountEdit();

        wrapper.findComponent(Checkbox).vm.$emit('update:modelValue', true);
        await nextTick();
        await wrapper.get('form').trigger('submit');

        expect(submittedPayload().execution_mode).toBe('definer');
    });
});

describe('reports/Form — controls', () => {
    it('shows the object type as read only text while editing', () => {
        const wrapper = mountEdit();

        expect(slot(wrapper, 'object-type').find('select').exists()).toBe(
            false,
        );
        expect(slot(wrapper, 'object-type').text()).toContain('Deals');
    });

    it('picks the object type from a combobox while creating', () => {
        const wrapper = mountForm();

        expect(
            slot(wrapper, 'object-type').find('select.ui-combobox').exists(),
        ).toBe(true);
    });

    it('renders no native select anywhere', () => {
        const wrapper = mountForm();

        const native = wrapper
            .findAll('select')
            .filter(
                (element) =>
                    !element.classes('ui-select') &&
                    !element.classes('ui-combobox'),
            );

        expect(native).toHaveLength(0);
    });

    it('hands the filter builder only the plain fields and hides its own action row', () => {
        const wrapper = mountForm();
        const builder = wrapper.findComponent(FilterBuilderStub);

        expect(builder.props('showActions')).toBe(false);

        const keys = (builder.props('fields') as FieldDefinition[]).map(
            (entry) => entry.key,
        );

        expect(keys).toEqual(FIELDS.map((entry) => entry.key));
        expect(keys).not.toContain('contacts.email');
    });

    it('states in German that the segment prefill is a one time copy', () => {
        const wrapper = mountForm();

        const hint = wrapper.get('[data-report-prefill-hint]').text();

        expect(hint.toLowerCase()).toContain('kopie');
        expect(wrapper.find('[data-report-prefill]').exists()).toBe(true);
    });

    it('hands the preview the live definition and the current presentation', () => {
        const wrapper = mountEdit();
        const preview = wrapper.findComponent(ReportPreviewStub);

        expect(preview.exists()).toBe(true);
        expect(preview.props('presentation')).toBe('bar');
        expect(preview.props('bucket')).toBe('month');
        expect(typeof preview.props('payload')).toBe('function');
    });
});

describe('reports/Form — server side errors', () => {
    it('renders a bound message for every key the write path can reject', async () => {
        inertia.page.props.auth.authority = 'super_admin';

        const wrapper = mountEdit();

        const errors: Record<string, string> = {};

        ERROR_KEYS.forEach((key) => {
            errors[key] = `FEHLER_${key.toUpperCase()}`;
        });

        formState().errors = errors;
        await nextTick();

        expect(ERROR_KEYS).toHaveLength(11);

        ERROR_KEYS.forEach((key) => {
            expect(wrapper.text()).toContain(`FEHLER_${key.toUpperCase()}`);
        });
    });
});

const DRILL_DOWN_SOURCE = {
    reportId: '01REPORT0000000000000001',
    objectTypeSlug: 'deals',
};

function drillDownSourceOf(wrapper: VueWrapper): unknown {
    return wrapper.findComponent(ReportPreviewStub).props('drillDownSource');
}

describe('reports/Form — the preview only drills into a saved definition', () => {
    it('hands the saved report and its object type to the preview while editing', async () => {
        const wrapper = mountEdit();

        await nextTick();

        expect(drillDownSourceOf(wrapper)).toEqual(DRILL_DOWN_SOURCE);
    });

    it('offers no drill-down while creating, because nothing is saved yet', () => {
        expect(drillDownSourceOf(mountForm())).toBeNull();
    });

    it('withdraws the drill-down as soon as the definition differs from the saved one', async () => {
        const wrapper = mountEdit();

        await nextTick();
        await picker(wrapper, 'group-by-field').setValue('stage');

        expect(isSaveDisabled(wrapper)).toBe(false);
        expect(drillDownSourceOf(wrapper)).toBeNull();
    });

    it('offers no drill-down when the saved report names no object type', async () => {
        const wrapper = mountEdit({ object_type: null });

        await nextTick();

        expect(drillDownSourceOf(wrapper)).toBeNull();
    });
});
