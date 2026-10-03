import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import FilterCondition from '@/components/engine/filter/FilterCondition.vue';
import { MultiSelect } from '@/components/ui/multi-select';
import { selectStubs } from '@/tests/selectStubs';
import type { FieldDefinition } from '@/types/fields';

const FIELDS: FieldDefinition[] = [
    {
        key: 'stage',
        field_type: 'text_short',
        label: 'Phase',
        is_required: false,
    },
    {
        key: 'owner_id',
        field_type: 'single_select',
        label: 'Besitzer',
        is_required: false,
        config: {
            options: [
                { value: 'user-1', label: 'Ada Lovelace' },
                { value: 'user-2', label: 'Alan Turing' },
            ],
        },
    },
];

type Wrapper = ReturnType<typeof mount>;

const Host = defineComponent({
    props: {
        field: { type: String, required: true },
        operator: { type: String, required: true },
    },
    setup(props) {
        return () =>
            h(
                FilterBuilder,
                {
                    fields: FIELDS,
                    showActions: false,
                    modelValue: {
                        combinator: 'and',
                        conditions: [
                            {
                                field: props.field,
                                operator: props.operator,
                                value: undefined,
                            },
                        ],
                    },
                },
                {},
            );
    },
});

function mountWith(field: string, operator: string): Wrapper {
    return mount(Host, {
        props: { field, operator },
        global: { stubs: selectStubs },
    });
}

describe('FilterCondition — value editor', () => {
    it('keeps a free text input for fields without options', () => {
        const wrapper = mountWith('stage', 'equals');

        expect(wrapper.find('input[aria-label="Wert"]').exists()).toBe(true);
        expect(wrapper.findComponent(MultiSelect).exists()).toBe(false);
    });

    it('offers the configured options for a multi value operator', async () => {
        const wrapper = mountWith('owner_id', 'in');

        await nextTick();

        const multi = wrapper.findComponent(MultiSelect);

        expect(multi.exists()).toBe(true);
        expect(multi.props('options')).toEqual([
            { value: 'user-1', label: 'Ada Lovelace' },
            { value: 'user-2', label: 'Alan Turing' },
        ]);
        expect(wrapper.find('input[aria-label="Werte"]').exists()).toBe(false);
    });

    it('writes the picked options into the condition', async () => {
        const wrapper = mountWith('owner_id', 'in');

        await nextTick();

        wrapper
            .findComponent(MultiSelect)
            .vm.$emit('update:modelValue', ['user-2']);

        await nextTick();

        const emitted = wrapper
            .findComponent(FilterBuilder)
            .emitted('update:modelValue')
            ?.at(-1)?.[0] as {
            conditions: Array<{ value: unknown }>;
        };

        expect(emitted.conditions[0].value).toEqual(['user-2']);
    });

    it('renders the condition component for every field', () => {
        expect(
            mountWith('stage', 'equals')
                .findComponent(FilterCondition)
                .exists(),
        ).toBe(true);
    });
});
