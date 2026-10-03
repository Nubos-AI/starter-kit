import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReportDefinitionFields from '@/components/reports/ReportDefinitionFields.vue';
import { comboboxPrimitiveStubs } from '@/tests/comboboxPrimitiveStubs';
import { selectStubs } from '@/tests/selectStubs';
import type { FieldDefinition } from '@/types/fields';

type Wrapper = VueWrapper;

const ENGLISH_WORDS = [/\bthe\b/i, /\byou\b/i, /\bcannot\b/i, /\bfield\b/i];

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

const PLAIN_FIELDS: FieldDefinition[] = [
    field(),
    field({ key: 'amount', field_type: 'money', label: 'Betrag' }),
    field({ key: 'closed_at', field_type: 'date', label: 'Abschluss' }),
    field({
        key: 'owner_id',
        field_type: 'single_select',
        label: 'Besitzer',
    }),
    field({ key: 'team_id', field_type: 'single_select', label: 'Team' }),
    field({
        key: 'stage_id',
        field_type: 'single_select',
        label: 'Status',
    }),
];

const LINKED_FIELDS: FieldDefinition[] = [
    field({
        key: 'contacts.email',
        field_type: 'email',
        label: 'Kontakte › E-Mail',
        is_filterable: false,
        is_sortable: false,
    }),
    field({
        key: 'contacts.revenue',
        field_type: 'money',
        label: 'Kontakte › Umsatz',
        is_filterable: false,
        is_sortable: false,
    }),
];

function mountFields(props: Record<string, unknown> = {}): Wrapper {
    return mount(ReportDefinitionFields, {
        props: {
            fields: PLAIN_FIELDS,
            linkedFields: LINKED_FIELDS,
            errors: {},
            aggregationType: 'count',
            aggregationFieldKey: null,
            groupByFieldKey: null,
            groupByBucket: null,
            seriesFieldKey: null,
            chartType: 'bar',
            ...props,
        },
        global: { stubs: { ...selectStubs, ...comboboxPrimitiveStubs } },
    });
}

it('normalizes an initially ungrouped chart to a metric', () => {
    const wrapper = mountFields({ groupByFieldKey: null, chartType: 'bar' });

    expect(wrapper.emitted('update:chartType')).toEqual([['metric']]);
});

function slot(
    wrapper: Wrapper,
    name: string,
): Omit<DOMWrapper<Element>, 'exists'> {
    return wrapper.get(`[data-report-field="${name}"]`);
}

function items(wrapper: Wrapper, name: string): DOMWrapper<Element>[] {
    return slot(wrapper, name).findAll('[data-combobox-item]');
}

function itemLabels(wrapper: Wrapper, name: string): string[] {
    return items(wrapper, name).map((item) => item.text());
}

function item(
    wrapper: Wrapper,
    name: string,
    label: string,
): DOMWrapper<Element> {
    const found = items(wrapper, name).find((entry) =>
        entry.text().includes(label),
    );

    if (found === undefined) {
        throw new Error(`Option "${label}" is missing from "${name}"`);
    }

    return found;
}

function optionValues(wrapper: Wrapper, name: string): string[] {
    return slot(wrapper, name)
        .findAll('option')
        .map((option) => option.attributes('value') ?? '');
}

describe('ReportDefinitionFields — permissibility', () => {
    it('keeps a field the aggregate cannot use in the list, disabled and with a German reason', () => {
        const wrapper = mountFields({
            aggregationType: 'sum',
            linkedFields: [],
        });

        expect(items(wrapper, 'aggregation-field')).toHaveLength(
            PLAIN_FIELDS.length,
        );

        const text = item(wrapper, 'aggregation-field', 'Phase');
        const money = item(wrapper, 'aggregation-field', 'Betrag');

        expect(text.attributes('disabled')).toBeDefined();
        expect(money.attributes('disabled')).toBeUndefined();

        const reason = text.attributes('title') ?? '';

        expect(reason.trim().length).toBeGreaterThan(10);

        ENGLISH_WORDS.forEach((word) => {
            expect(reason).not.toMatch(word);
        });
    });

    it('offers every field again as soon as the aggregate is a count', () => {
        const wrapper = mountFields({
            aggregationType: 'count',
            linkedFields: [],
        });

        items(wrapper, 'aggregation-field').forEach((entry) => {
            expect(entry.attributes('disabled')).toBeUndefined();
        });
    });

    it('never restricts the grouping field by the chosen aggregate', () => {
        const wrapper = mountFields({ aggregationType: 'sum' });

        items(wrapper, 'group-by-field').forEach((entry) => {
            expect(entry.attributes('disabled')).toBeUndefined();
        });
    });

    it('refuses to pick a disabled option', async () => {
        const wrapper = mountFields({
            aggregationType: 'sum',
            linkedFields: [],
        });

        await item(wrapper, 'aggregation-field', 'Phase').trigger('click');

        expect(wrapper.emitted('update:aggregationFieldKey')).toBeUndefined();
    });

    it('emits the picked key for a permitted option', async () => {
        const wrapper = mountFields({
            aggregationType: 'sum',
            linkedFields: [],
        });

        await item(wrapper, 'aggregation-field', 'Betrag').trigger('click');

        expect(wrapper.emitted('update:aggregationFieldKey')).toEqual([
            ['amount'],
        ]);
    });
});

describe('ReportDefinitionFields — system and linked fields', () => {
    it('offers the three system fields in the grouping and in the aggregate alike', () => {
        const wrapper = mountFields();

        const grouping = itemLabels(wrapper, 'group-by-field').join('|');
        const aggregate = itemLabels(wrapper, 'aggregation-field').join('|');
        const series = itemLabels(wrapper, 'series-field').join('|');

        ['Besitzer', 'Team', 'Status'].forEach((label) => {
            expect(grouping).toContain(label);
            expect(aggregate).toContain(label);
            expect(series).toContain(label);
        });

        expect(
            item(wrapper, 'aggregation-field', 'Besitzer').attributes(
                'disabled',
            ),
        ).toBeUndefined();
    });

    it('offers linked fields for the aggregate and for the grouping', () => {
        const wrapper = mountFields();

        expect(
            item(wrapper, 'aggregation-field', 'Kontakte › E-Mail').exists(),
        ).toBe(true);
        expect(
            item(wrapper, 'group-by-field', 'Kontakte › Umsatz').exists(),
        ).toBe(true);
    });

    it('never offers a linked field as a series, because the server refuses it', () => {
        const wrapper = mountFields();

        itemLabels(wrapper, 'series-field').forEach((label) => {
            expect(label).not.toContain('Kontakte');
        });

        expect(items(wrapper, 'series-field')).toHaveLength(
            PLAIN_FIELDS.length,
        );
    });

    it('disables a linked field for an average and for a distinct count', () => {
        const average = mountFields({ aggregationType: 'avg' });
        const linkedForAverage = item(
            average,
            'aggregation-field',
            'Kontakte › Umsatz',
        );

        expect(linkedForAverage.attributes('disabled')).toBeDefined();
        expect(
            (linkedForAverage.attributes('title') ?? '').length,
        ).toBeGreaterThan(10);

        const distinct = mountFields({ aggregationType: 'distinct_count' });

        expect(
            item(distinct, 'aggregation-field', 'Kontakte › Umsatz').attributes(
                'disabled',
            ),
        ).toBeDefined();

        const sum = mountFields({ aggregationType: 'sum' });

        expect(
            item(sum, 'aggregation-field', 'Kontakte › Umsatz').attributes(
                'disabled',
            ),
        ).toBeUndefined();
    });
});

describe('ReportDefinitionFields — time bucket', () => {
    it('offers the bucket only once the grouping field carries a date', () => {
        expect(
            mountFields({ groupByFieldKey: 'stage' })
                .find('[data-report-field="group-by-bucket"]')
                .exists(),
        ).toBe(false);

        expect(
            mountFields({ groupByFieldKey: 'closed_at' })
                .find('[data-report-field="group-by-bucket"]')
                .exists(),
        ).toBe(true);

        expect(
            mountFields({ groupByFieldKey: null })
                .find('[data-report-field="group-by-bucket"]')
                .exists(),
        ).toBe(false);
    });

    it('clears the bucket when the grouping field stops being a date', async () => {
        const wrapper = mountFields({
            groupByFieldKey: 'closed_at',
            groupByBucket: 'month',
        });

        await wrapper.setProps({ groupByFieldKey: 'stage' });

        expect(wrapper.emitted('update:groupByBucket')).toEqual([[null]]);
        expect(
            wrapper.find('[data-report-field="group-by-bucket"]').exists(),
        ).toBe(false);
    });

    it('offers all five buckets with German labels', () => {
        const wrapper = mountFields({ groupByFieldKey: 'closed_at' });

        expect(optionValues(wrapper, 'group-by-bucket')).toEqual([
            'day',
            'week',
            'month',
            'quarter',
            'year',
        ]);
        expect(slot(wrapper, 'group-by-bucket').text()).toContain('Quartal');
    });
});

describe('ReportDefinitionFields — closed enumerations', () => {
    it('offers every aggregate with a German label', () => {
        const wrapper = mountFields();

        expect(optionValues(wrapper, 'aggregation-type')).toEqual([
            'count',
            'sum',
            'avg',
            'min',
            'max',
            'distinct_count',
        ]);
        expect(slot(wrapper, 'aggregation-type').text()).toContain('Anzahl');
    });

    it('offers all six presentations with German labels', () => {
        const wrapper = mountFields();

        expect(optionValues(wrapper, 'chart-type').sort()).toEqual(
            ['metric', 'bar', 'line', 'area', 'pie', 'donut'].sort(),
        );
        expect(slot(wrapper, 'chart-type').text()).toContain('Kennzahl');
        expect(slot(wrapper, 'chart-type').text()).toContain('Fläche');
        expect(slot(wrapper, 'chart-type').text()).toContain('Donut');
    });

    it('emits the picked aggregate', async () => {
        const wrapper = mountFields();

        await slot(wrapper, 'aggregation-type')
            .find('select.ui-select')
            .setValue('sum');

        expect(wrapper.emitted('update:aggregationType')).toEqual([['sum']]);
    });
});

describe('ReportDefinitionFields — edges', () => {
    it('renders empty pickers without failing when the object type carries no field', () => {
        const wrapper = mountFields({ fields: [], linkedFields: [] });

        expect(items(wrapper, 'aggregation-field')).toHaveLength(0);
        expect(items(wrapper, 'group-by-field')).toHaveLength(0);
        expect(items(wrapper, 'series-field')).toHaveLength(0);
        expect(
            slot(wrapper, 'aggregation-field')
                .find('[data-combobox-empty]')
                .exists(),
        ).toBe(true);
    });

    it('renders a bound message for every definition error the server can send', () => {
        const errors = {
            aggregation_type: 'FEHLER_AGGREGAT',
            aggregation_field_key: 'FEHLER_AGGREGATFELD',
            group_by_field_key: 'FEHLER_GRUPPIERUNG',
            group_by_bucket: 'FEHLER_BUCKET',
            series_field_key: 'FEHLER_SERIE',
            chart_type: 'FEHLER_DARSTELLUNG',
        };

        const wrapper = mountFields({
            errors,
            groupByFieldKey: 'closed_at',
        });

        Object.values(errors).forEach((message) => {
            expect(wrapper.text()).toContain(message);
        });
    });

    it('never renders a native select for a field picker', () => {
        const wrapper = mountFields();

        ['aggregation-field', 'group-by-field', 'series-field'].forEach(
            (name) => {
                expect(slot(wrapper, name).find('select').exists()).toBe(false);
                expect(
                    slot(wrapper, name).find('[data-combobox]').exists(),
                ).toBe(true);
            },
        );
    });
});

describe('ReportDefinitionFields — presentation without a grouping', () => {
    function disabledPresentations(wrapper: Wrapper): string[] {
        return slot(wrapper, 'chart-type')
            .findAll('option')
            .filter((option) => option.attributes('disabled') !== undefined)
            .map((option) => option.attributes('value') ?? '');
    }

    it('leaves only the single figure selectable while nothing is grouped', () => {
        const wrapper = mountFields({ groupByFieldKey: null });

        expect(disabledPresentations(wrapper).sort()).toEqual(
            ['area', 'bar', 'donut', 'line', 'pie'].sort(),
        );
    });

    it('frees every presentation again once a grouping is chosen', () => {
        const wrapper = mountFields({ groupByFieldKey: 'stage' });

        expect(disabledPresentations(wrapper)).toEqual([]);
    });

    it('falls back to the single figure when the grouping is taken away', async () => {
        const wrapper = mountFields({
            groupByFieldKey: 'stage',
            chartType: 'bar',
        });

        await wrapper.setProps({ groupByFieldKey: null });

        expect(wrapper.emitted('update:chartType')?.at(-1)).toEqual(['metric']);
    });

    it('keeps the chosen chart when a grouping is added', async () => {
        const wrapper = mountFields({
            groupByFieldKey: null,
            chartType: 'metric',
        });

        await wrapper.setProps({ groupByFieldKey: 'stage' });

        expect(wrapper.emitted('update:chartType')).toBeUndefined();
    });

    it('explains the restriction in German next to the presentation', () => {
        const hint = mountFields({ groupByFieldKey: null }).find(
            '[data-report-presentation-hint]',
        );

        expect(hint.exists()).toBe(true);
        expect(hint.text()).toContain('Gruppierung');
        ENGLISH_WORDS.forEach((word) => {
            expect(hint.text()).not.toMatch(word);
        });
    });

    it('drops the hint as soon as a grouping is chosen', () => {
        const wrapper = mountFields({ groupByFieldKey: 'stage' });

        expect(wrapper.find('[data-report-presentation-hint]').exists()).toBe(
            false,
        );
    });
});
