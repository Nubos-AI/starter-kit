import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RecordOwnerMenu from '@/components/engine/records/RecordOwnerMenu.vue';
import { comboboxPrimitiveStubs } from '@/tests/comboboxPrimitiveStubs';
import type { SelectOption } from '@/types/ui';

const people: SelectOption[] = [
    {
        value: 'u-1',
        label: 'Anna Albers',
        description: 'anna@nubos.de',
        avatar: { name: 'Anna Albers' },
    },
    {
        value: 'u-2',
        label: 'Sven Support',
        description: 'sven@nubos.de',
        avatar: { name: 'Sven Support' },
    },
];

function mountMenu(
    props: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(RecordOwnerMenu, {
        props: { options: people, modelValue: 'u-1', ...props },
        global: { stubs: comboboxPrimitiveStubs },
    });
}

describe('RecordOwnerMenu', () => {
    it('names the current owner in the trigger', () => {
        const wrapper = mountMenu();

        expect(wrapper.get('[data-people-trigger-label]').text()).toBe(
            'Anna Albers',
        );
        expect(wrapper.get('[data-people-trigger-caption]').text()).toBe(
            'Besitzer',
        );
    });

    it('says so when the record has no owner', () => {
        const wrapper = mountMenu({ modelValue: null });

        expect(wrapper.get('[data-people-trigger-label]').text()).toBe(
            'Kein Besitzer',
        );
    });

    it('falls back to a neutral label for an owner outside the option list', () => {
        const wrapper = mountMenu({ modelValue: 'u-gone' });

        expect(wrapper.get('[data-people-trigger-label]').text()).toBe(
            'Aktueller Besitzer',
        );
    });

    it('passes a chosen person on as the new owner', async () => {
        const wrapper = mountMenu();

        await wrapper.findAll('[data-combobox-item]')[1].trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['u-2']]);
    });
});
