import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import englishMessages from '@/../lang/en/i18n.json';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import RollupFieldEditor from '@/components/engine/objectType/RollupFieldEditor.vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { translationCatalogueKey } from '@/lib/i18n';
import { selectStubs } from '@/tests/selectStubs';
import type { FieldDefinition } from '@/types/fields';
import type { RollupFieldConfig, RollupTargetOption } from '@/types/rollups';
import {
    isRollupScope,
    rollupScopeOptions,
    toRollupFieldConfig,
} from '@/types/rollups';

interface EditorProps {
    fieldType: string;
    rollupTargets: RollupTargetOption[];
    initialConfig: RollupFieldConfig | null;
    errorMessage?: string;
}

const aggregateSelector = 'select[name="config[aggregate]"]';
const relationshipSelector = 'select[name="config[relationship_type_id]"]';
const sourceFieldSelector = '[name="config[source_field_key]"]';
const scopeSelector = 'select[name="config[scope]"]';
const subtreeOptionSelector =
    'select[name="config[scope]"] option[value="subtree"]';
const configSelector = '[name^="config\\["]';
const filterSelector = '[name^="config\\[filter\\]"]';
const regionSelector = '[data-testid="rollup-config"]';

const noHierarchyHint =
    'The selected relationship points at an object type without a hierarchy, so only direct children can be aggregated.';

const passthrough = { template: '<div><slot /></div>' };

const InputStub = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template:
        '<input class="filter-value" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
};

const ButtonStub = {
    props: ['disabled'],
    emits: ['click'],
    template:
        '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
};

const stubs = {
    ...selectStubs,
    SelectItemText: passthrough,
    SelectLabel: passthrough,
    Badge: passthrough,
    Input: InputStub,
    Button: ButtonStub,
};

const childFields: FieldDefinition[] = [
    {
        key: 'name',
        field_type: 'text_short',
        label: 'Name',
        is_required: false,
    },
    {
        key: 'amount',
        field_type: 'number',
        label: 'Amount',
        is_required: false,
    },
];

const partnerFields: FieldDefinition[] = [
    {
        key: 'title',
        field_type: 'text_short',
        label: 'Title',
        is_required: false,
    },
];

const hierarchyTarget: RollupTargetOption = {
    value: 'rel-hierarchy',
    label: 'Children',
    target_object_type_id: 'type-child',
    has_hierarchy: true,
    fields: childFields,
};

const flatTarget: RollupTargetOption = {
    value: 'rel-flat',
    label: 'Partners',
    target_object_type_id: 'type-partner',
    has_hierarchy: false,
    fields: partnerFields,
};

const hierarchyConfig: RollupFieldConfig = {
    aggregate: 'sum',
    relationship_type_id: 'rel-hierarchy',
    source_field_key: 'amount',
};

const flatConfig: RollupFieldConfig = {
    aggregate: 'sum',
    relationship_type_id: 'rel-flat',
    source_field_key: 'title',
};

function mountEditor(overrides: Partial<EditorProps> = {}) {
    return mount(RollupFieldEditor, {
        props: {
            fieldType: 'rollup',
            rollupTargets: [hierarchyTarget, flatTarget],
            initialConfig: null,
            ...overrides,
        } satisfies EditorProps,
        global: {
            provide: {
                [translationCatalogueKey as symbol]: () => ({
                    locale: 'en',
                    fallbackLocale: 'en',
                    messages: { i18n: englishMessages },
                }),
            },
            stubs,
        },
    });
}

describe('RollupFieldEditor — visibility and round trip', () => {
    it('renders no configuration control at all for a field type other than roll-up', () => {
        const rollup = mountEditor();
        const text = mountEditor({ fieldType: 'text_short' });

        expect(rollup.find(aggregateSelector).exists()).toBe(true);
        expect(rollup.find(relationshipSelector).exists()).toBe(true);
        expect(rollup.find(sourceFieldSelector).exists()).toBe(true);
        expect(rollup.find(scopeSelector).exists()).toBe(true);

        expect(text.findAll(configSelector)).toHaveLength(0);
        expect(text.findComponent(FilterBuilder).exists()).toBe(false);
    });

    it('renders the stored configuration so that saving does not wipe it', () => {
        const wrapper = mountEditor({
            initialConfig: {
                aggregate: 'avg',
                relationship_type_id: 'rel-hierarchy',
                source_field_key: 'amount',
                scope: 'subtree',
            },
        });

        expect(
            wrapper.get<HTMLSelectElement>(aggregateSelector).element.value,
        ).toBe('avg');
        expect(
            wrapper.get<HTMLSelectElement>(relationshipSelector).element.value,
        ).toBe('rel-hierarchy');
        expect(
            wrapper.get<HTMLInputElement>(sourceFieldSelector).element.value,
        ).toBe('amount');
        expect(
            wrapper.get<HTMLSelectElement>(scopeSelector).element.value,
        ).toBe('subtree');
    });
});

describe('RollupFieldEditor — aggregation scope', () => {
    it('locks the entire-subtree option when the target object type has no hierarchy', () => {
        const withoutHierarchy = mountEditor({ initialConfig: flatConfig });
        const withHierarchy = mountEditor({ initialConfig: hierarchyConfig });

        expect(
            withoutHierarchy.get(subtreeOptionSelector).attributes('disabled'),
        ).toBeDefined();
        expect(withoutHierarchy.text()).toContain(noHierarchyHint);

        expect(
            withHierarchy.get(subtreeOptionSelector).attributes('disabled'),
        ).toBeUndefined();
        expect(withHierarchy.text()).not.toContain(noHierarchyHint);
    });

    it('falls back to direct children when the user switches to a relationship without a hierarchy', async () => {
        const wrapper = mountEditor({
            initialConfig: { ...hierarchyConfig, scope: 'subtree' },
        });

        expect(
            wrapper.get<HTMLSelectElement>(scopeSelector).element.value,
        ).toBe('subtree');

        await wrapper.get(relationshipSelector).setValue('rel-flat');

        expect(
            wrapper.get<HTMLSelectElement>(scopeSelector).element.value,
        ).toBe('direct_children');
        expect(
            wrapper.get(subtreeOptionSelector).attributes('disabled'),
        ).toBeDefined();
    });
});

describe('RollupFieldEditor — filter condition', () => {
    it('reuses the existing filter builder and offers the fields of the target object type', async () => {
        const wrapper = mountEditor({ initialConfig: hierarchyConfig });

        const builder = wrapper.findComponent(FilterBuilder);

        expect(builder.exists()).toBe(true);
        expect(builder.props('fields')).toEqual(childFields);

        await wrapper.get(relationshipSelector).setValue('rel-flat');

        expect(wrapper.findComponent(FilterBuilder).props('fields')).toEqual(
            partnerFields,
        );
    });

    it('renders no filter input while no condition has been added', () => {
        const wrapper = mountEditor({ initialConfig: hierarchyConfig });

        expect(wrapper.findComponent(FilterBuilder).exists()).toBe(true);
        expect(wrapper.findAll(filterSelector)).toHaveLength(0);
    });

    it('renders the indexed hidden inputs of a nested filter tree with its exact names', () => {
        const filter: FilterGroupNode = {
            combinator: 'or',
            conditions: [
                { field: 'name', operator: 'in', value: ['a', 'b'] },
                { field: 'amount', operator: 'inRange', value: 1, valueTo: 9 },
                {
                    combinator: 'and',
                    conditions: [
                        { field: 'name', operator: 'equals', value: 'x' },
                    ],
                },
            ],
        };

        const wrapper = mountEditor({
            initialConfig: { ...hierarchyConfig, filter },
        });

        expect(
            wrapper
                .findAll(filterSelector)
                .map((entry) => entry.attributes('name')),
        ).toEqual([
            'config[filter][combinator]',
            'config[filter][conditions][0][field]',
            'config[filter][conditions][0][operator]',
            'config[filter][conditions][0][value][0]',
            'config[filter][conditions][0][value][1]',
            'config[filter][conditions][1][field]',
            'config[filter][conditions][1][operator]',
            'config[filter][conditions][1][value]',
            'config[filter][conditions][1][valueTo]',
            'config[filter][conditions][2][combinator]',
            'config[filter][conditions][2][conditions][0][field]',
            'config[filter][conditions][2][conditions][0][operator]',
            'config[filter][conditions][2][conditions][0][value]',
        ]);

        const valueOf = (name: string): string =>
            wrapper.get<HTMLInputElement>(`[name="${name}"]`).element.value;

        expect(valueOf('config[filter][combinator]')).toBe('or');
        expect(valueOf('config[filter][conditions][0][value][0]')).toBe('a');
        expect(valueOf('config[filter][conditions][0][value][1]')).toBe('b');
        expect(valueOf('config[filter][conditions][1][value]')).toBe('1');
        expect(valueOf('config[filter][conditions][1][valueTo]')).toBe('9');
        expect(valueOf('config[filter][conditions][2][combinator]')).toBe(
            'and',
        );
        expect(
            valueOf('config[filter][conditions][2][conditions][0][value]'),
        ).toBe('x');
    });
});

describe('RollupFieldEditor — server error', () => {
    it('renders the configuration error and marks the configuration group invalid', () => {
        const message = 'The entire subtree needs a hierarchy on the target.';

        const withError = mountEditor({
            initialConfig: hierarchyConfig,
            errorMessage: message,
        });
        const withoutError = mountEditor({ initialConfig: hierarchyConfig });

        expect(withError.text()).toContain(message);
        expect(withError.get(regionSelector).attributes('aria-invalid')).toBe(
            'true',
        );

        expect(withoutError.text()).not.toContain(message);
        expect(
            withoutError.get(regionSelector).attributes('aria-invalid'),
        ).toBeUndefined();
    });
});

describe('isRollupScope', () => {
    it('accepts the two known scopes', () => {
        expect(isRollupScope('direct_children')).toBe(true);
        expect(isRollupScope('subtree')).toBe(true);
    });

    it('rejects a value that is not a known scope string', () => {
        expect(isRollupScope(null)).toBe(false);
        expect(isRollupScope(undefined)).toBe(false);
        expect(isRollupScope('')).toBe(false);
        expect(isRollupScope(19)).toBe(false);
        expect(isRollupScope({ scope: 'subtree' })).toBe(false);
        expect(isRollupScope(['subtree'])).toBe(false);
        expect(isRollupScope('toString')).toBe(false);
        expect(isRollupScope('constructor')).toBe(false);
    });

    it('narrows an accepted value so it can be looked up in the scope options', () => {
        const value: unknown = 'subtree';

        if (!isRollupScope(value)) {
            throw new Error('the guard must accept a known scope');
        }

        expect(rollupScopeOptions.map((entry) => entry.value)).toEqual([
            'direct_children',
            'subtree',
        ]);
        expect(
            rollupScopeOptions.find((entry) => entry.value === value)?.label,
        ).toBe('Entire subtree');
    });
});

describe('toRollupFieldConfig', () => {
    const storedFilter: FilterGroupNode = {
        combinator: 'and',
        conditions: [
            { field: 'amount', operator: 'greaterThan', value: 5 },
            {
                combinator: 'or',
                conditions: [{ field: 'name', operator: 'equals', value: 'x' }],
            },
        ],
    };

    it('maps a missing configuration to null', () => {
        expect(toRollupFieldConfig(null)).toBeNull();
        expect(toRollupFieldConfig(undefined)).toBeNull();
    });

    it('maps an empty configuration to an empty roll-up configuration', () => {
        expect(toRollupFieldConfig({})).toEqual({});
    });

    it('takes over every key of a stored configuration unchanged', () => {
        expect(
            toRollupFieldConfig({
                aggregate: 'avg',
                relationship_type_id: 'rel-hierarchy',
                source_field_key: 'amount',
                scope: 'subtree',
                filter: storedFilter,
            }),
        ).toEqual({
            aggregate: 'avg',
            relationship_type_id: 'rel-hierarchy',
            source_field_key: 'amount',
            scope: 'subtree',
            filter: storedFilter,
        });
    });

    it('keeps the stored filter tree identical instead of rebuilding it', () => {
        expect(toRollupFieldConfig({ filter: storedFilter })?.filter).toBe(
            storedFilter,
        );
    });

    it('drops a scope that is not a known roll-up scope', () => {
        expect(toRollupFieldConfig({ scope: 'entire_universe' })).toEqual({});
        expect(toRollupFieldConfig({ scope: null })).toEqual({});
    });

    it('drops a value whose type does not fit its configuration key', () => {
        expect(toRollupFieldConfig({ aggregate: 19 })).toEqual({});
        expect(toRollupFieldConfig({ relationship_type_id: 19 })).toEqual({});
        expect(toRollupFieldConfig({ source_field_key: null })).toEqual({});
    });

    it('drops a filter that is not a well formed condition group', () => {
        expect(
            toRollupFieldConfig({
                filter: { combinator: 'xor', conditions: [] },
            }),
        ).toEqual({});
        expect(toRollupFieldConfig({ filter: { conditions: [] } })).toEqual({});
        expect(toRollupFieldConfig({ filter: { combinator: 'and' } })).toEqual(
            {},
        );
        expect(
            toRollupFieldConfig({
                filter: { combinator: 'and', conditions: 'all' },
            }),
        ).toEqual({});
        expect(toRollupFieldConfig({ filter: null })).toEqual({});
        expect(toRollupFieldConfig({ filter: 'and' })).toEqual({});
    });

    it('keeps the valid keys while dropping the invalid ones', () => {
        expect(
            toRollupFieldConfig({
                aggregate: 'sum',
                scope: 'entire_universe',
                filter: { combinator: 'xor', conditions: [] },
            }),
        ).toEqual({ aggregate: 'sum' });
    });
});
