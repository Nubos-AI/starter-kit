import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import type { FilterValue } from '@/composables/useFilterTree';
import type { FieldDefinition } from '@/types/fields';

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
    Select: passthrough,
    SelectContent: passthrough,
    SelectGroup: passthrough,
    SelectItem: passthrough,
    SelectItemText: passthrough,
    SelectLabel: passthrough,
    SelectTrigger: passthrough,
    SelectValue: passthrough,
    Badge: passthrough,
    Input: InputStub,
    Button: ButtonStub,
};

const fields: FieldDefinition[] = [
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

type ConditionSeed = {
    field: string;
    operator: string;
    value?: FilterValue;
    valueTo?: FilterValue;
};

type GroupSeed = {
    combinator: 'and' | 'or';
    conditions: Array<GroupSeed | ConditionSeed>;
};

function cond(
    field: string,
    operator: string,
    extra: { value?: FilterValue; valueTo?: FilterValue } = {},
): ConditionSeed {
    return { field, operator, ...extra };
}

function group(
    combinator: 'and' | 'or',
    conditions: Array<GroupSeed | ConditionSeed>,
): GroupSeed {
    return { combinator, conditions };
}

function mountBuilder(modelValue?: GroupSeed) {
    return mount(FilterBuilder, {
        props: {
            fields,
            ...(modelValue ? { modelValue } : {}),
        },
        global: { stubs },
    });
}

function buttonByText(wrapper: ReturnType<typeof mount>, text: string) {
    return wrapper.findAll('button').find((b) => b.text().includes(text));
}

type AppliedTree = {
    combinator: string;
    conditions: Array<Record<string, unknown>>;
};

async function apply(wrapper: ReturnType<typeof mount>): Promise<AppliedTree> {
    await buttonByText(wrapper, 'Anwenden')!.trigger('click');
    const events = wrapper.emitted('apply');
    expect(events).toBeTruthy();

    return events!.at(-1)![0] as AppliedTree;
}

describe('FilterBuilder — nested AND/OR groups (SC-1)', () => {
    it('recursively renders a subgroup as a second FilterGroup level', () => {
        const wrapper = mountBuilder(
            group('and', [
                group('or', [cond('name', 'contains', { value: 'x' })]),
            ]),
        );

        const groups = wrapper.findAll('[data-filter-group]');
        expect(groups).toHaveLength(2);

        const depths = groups.map((g) => g.attributes('data-depth')).sort();
        expect(depths).toEqual(['0', '1']);

        expect(wrapper.text()).toContain('UND');
        expect(wrapper.text()).toContain('ODER');
    });
});

describe('FilterBuilder — data-driven operators per field type (SC-1)', () => {
    it('offers text operators for a text field and hides numeric-only ones', () => {
        const wrapper = mountBuilder(
            group('and', [cond('name', 'contains', { value: 'x' })]),
        );

        expect(wrapper.text()).toContain('Enthält');
        expect(wrapper.text()).toContain('Beginnt mit');
        expect(wrapper.text()).not.toContain('Größer als');
        expect(wrapper.text()).not.toContain('Im Bereich');
    });

    it('offers numeric operators for a number field and hides text-only ones', () => {
        const wrapper = mountBuilder(
            group('and', [cond('amount', 'greaterThan', { value: 1 })]),
        );

        expect(wrapper.text()).toContain('Größer als');
        expect(wrapper.text()).toContain('Im Bereich');
        expect(wrapper.text()).not.toContain('Enthält');
    });
});

describe('FilterBuilder — arity drives the value widget (SC-1)', () => {
    it('renders no value input for a none-arity operator', () => {
        const wrapper = mountBuilder(group('and', [cond('name', 'blank')]));

        expect(wrapper.findAll('input')).toHaveLength(0);
    });

    it('renders a single value input for a single-arity operator', () => {
        const wrapper = mountBuilder(
            group('and', [cond('name', 'equals', { value: 'foo' })]),
        );

        expect(wrapper.findAll('input')).toHaveLength(1);
    });

    it('renders two value inputs for a range-arity operator', () => {
        const wrapper = mountBuilder(
            group('and', [cond('amount', 'inRange', { value: 1, valueTo: 9 })]),
        );

        expect(wrapper.findAll('input')).toHaveLength(2);
    });

    it('renders exactly one comma-joined value input for a multi-arity operator', () => {
        const wrapper = mountBuilder(
            group('and', [cond('name', 'in', { value: ['a', 'b'] })]),
        );

        const inputs = wrapper.findAll('input');
        expect(inputs).toHaveLength(1);
        expect((inputs[0].element as HTMLInputElement).value).toBe('a, b');
    });
});

describe('FilterBuilder — emitted tree is compiler-shaped (SC-1)', () => {
    it('emits a Group|Condition tree with no id keys for a single-arity condition', async () => {
        const wrapper = mountBuilder(
            group('and', [cond('name', 'equals', { value: 'foo' })]),
        );

        const tree = await apply(wrapper);

        expect(tree).toEqual({
            combinator: 'and',
            conditions: [{ field: 'name', operator: 'equals', value: 'foo' }],
        });
        expect('id' in tree).toBe(false);
        expect('id' in tree.conditions[0]).toBe(false);
    });

    it('omits value/valueTo for a none-arity condition', async () => {
        const wrapper = mountBuilder(group('and', [cond('name', 'blank')]));

        const tree = await apply(wrapper);

        expect(tree).toEqual({
            combinator: 'and',
            conditions: [{ field: 'name', operator: 'blank' }],
        });
        const condition = tree.conditions[0];
        expect('value' in condition).toBe(false);
        expect('valueTo' in condition).toBe(false);
    });

    it('emits an array value for an in condition', async () => {
        const wrapper = mountBuilder(
            group('and', [cond('name', 'in', { value: ['a', 'b'] })]),
        );

        const tree = await apply(wrapper);

        expect(tree).toEqual({
            combinator: 'and',
            conditions: [{ field: 'name', operator: 'in', value: ['a', 'b'] }],
        });
        expect(Array.isArray(tree.conditions[0].value)).toBe(true);
    });

    it('resets an array value to a scalar shape when switching from a multi to a single operator', async () => {
        const SelectStub = {
            props: ['modelValue'],
            emits: ['update:modelValue'],
            template:
                '<div class="select-stub" :data-model-value="modelValue"><slot /></div>',
        };

        const wrapper = mount(FilterBuilder, {
            props: {
                fields,
                modelValue: group('and', [
                    cond('name', 'in', { value: ['a', 'b'] }),
                ]),
            },
            global: { stubs: { ...stubs, Select: SelectStub } },
        });

        const operatorSelect = wrapper.findComponent('[data-model-value="in"]');
        expect(operatorSelect.exists()).toBe(true);

        await (operatorSelect as VueWrapper).vm.$emit(
            'update:modelValue',
            'equals',
        );
        await wrapper.vm.$nextTick();

        await wrapper.find('input.filter-value').setValue('foo');

        const tree = await apply(wrapper);
        const condition = tree.conditions[0];

        expect(condition.operator).toBe('equals');
        expect(condition.value).toBe('foo');
        expect(Array.isArray(condition.value)).toBe(false);
    });

    it('omits value for has and hasNot relation conditions', async () => {
        const wrapper = mountBuilder(
            group('and', [
                cond('name', 'has', { value: 'x' }),
                cond('amount', 'hasNot', { value: 'y' }),
            ]),
        );

        const tree = await apply(wrapper);

        expect(tree.conditions).toEqual([
            { field: 'name', operator: 'has' },
            { field: 'amount', operator: 'hasNot' },
        ]);

        for (const condition of tree.conditions) {
            expect('value' in condition).toBe(false);
        }
    });

    it('emits an empty group when nothing has been added', async () => {
        const wrapper = mountBuilder();

        const tree = await apply(wrapper);

        expect(tree).toEqual({ combinator: 'and', conditions: [] });
    });

    it('prunes an incomplete condition and emits only the complete one', async () => {
        const wrapper = mountBuilder(
            group('and', [
                cond('name', 'equals', { value: 'foo' }),
                cond('amount', 'inRange', { value: 1 }),
            ]),
        );

        const tree = await apply(wrapper);

        expect(tree).toEqual({
            combinator: 'and',
            conditions: [{ field: 'name', operator: 'equals', value: 'foo' }],
        });
    });

    it('drops a subgroup that is empty after pruning', async () => {
        const wrapper = mountBuilder(
            group('and', [
                cond('name', 'equals', { value: 'foo' }),
                group('or', [cond('amount', 'inRange', { value: 1 })]),
            ]),
        );

        const tree = await apply(wrapper);

        expect(tree).toEqual({
            combinator: 'and',
            conditions: [{ field: 'name', operator: 'equals', value: 'foo' }],
        });
    });

    it('keeps the root group even when every condition is pruned', async () => {
        const wrapper = mountBuilder(
            group('and', [cond('amount', 'inRange', { value: 1 })]),
        );

        const tree = await apply(wrapper);

        expect(tree).toEqual({ combinator: 'and', conditions: [] });
    });
});

describe('FilterBuilder — client-side cap guard (SC-1)', () => {
    it('disables add-group at max depth while the root stays enabled', () => {
        let node: GroupSeed = group('and', []);

        for (let level = 0; level < 5; level += 1) {
            node = group('and', [node]);
        }

        const wrapper = mountBuilder(node);
        const addGroup = wrapper.findAll('[data-add-group]');

        expect(addGroup.length).toBeGreaterThanOrEqual(2);
        expect(addGroup[0].attributes('disabled')).toBeUndefined();
        expect(addGroup.at(-1)!.attributes('disabled')).toBeDefined();
    });

    it('disables add-condition once the node cap is reached', () => {
        const conditions = Array.from({ length: 50 }, (_, index) =>
            cond('name', 'equals', { value: String(index) }),
        );

        const wrapper = mountBuilder(group('and', conditions));

        expect(
            wrapper.find('[data-add-condition]').attributes('disabled'),
        ).toBeDefined();
    });

    it('keeps add-condition enabled below the node cap', () => {
        const wrapper = mountBuilder(
            group('and', [cond('name', 'equals', { value: 'x' })]),
        );

        expect(
            wrapper.find('[data-add-condition]').attributes('disabled'),
        ).toBeUndefined();
    });
});

describe('FilterBuilder — removal and reset (SC-1)', () => {
    it('removing a condition reduces the emitted tree', async () => {
        const wrapper = mountBuilder(
            group('and', [
                cond('name', 'equals', { value: 'a' }),
                cond('amount', 'greaterThan', { value: 1 }),
            ]),
        );

        const removeButtons = wrapper.findAll('[data-remove-node]');
        expect(removeButtons.length).toBeGreaterThanOrEqual(2);

        await removeButtons[0].trigger('click');
        const tree = await apply(wrapper);

        expect(tree.conditions).toHaveLength(1);
        expect(tree.conditions[0]).toEqual({
            field: 'amount',
            operator: 'greaterThan',
            value: 1,
        });
    });

    it('emits reset when the reset action is triggered', async () => {
        const wrapper = mountBuilder(
            group('and', [cond('name', 'equals', { value: 'a' })]),
        );

        await buttonByText(wrapper, 'Zurücksetzen')!.trigger('click');

        expect(wrapper.emitted('reset')).toHaveLength(1);
    });
});
