import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RecordCollaboratorsMenu from '@/components/engine/records/RecordCollaboratorsMenu.vue';
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
    {
        value: 'u-3',
        label: 'Rita Vertrieb',
        description: 'rita@nubos.de',
        avatar: { name: 'Rita Vertrieb' },
    },
];

function mountMenu(
    modelValue: string[] = [],
    props: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(RecordCollaboratorsMenu, {
        props: { options: people, modelValue, ...props },
        global: { stubs: comboboxPrimitiveStubs },
    });
}

function label(wrapper: ReturnType<typeof mount>): string {
    return wrapper.get('[data-people-trigger-label]').text();
}

describe('RecordCollaboratorsMenu', () => {
    it('says so when nobody collaborates', () => {
        const wrapper = mountMenu();

        expect(label(wrapper)).toBe('Keine Mitwirkenden');
        expect(wrapper.get('[data-people-trigger-caption]').text()).toBe(
            'Mitwirkende',
        );
    });

    it('names the single collaborator', () => {
        const wrapper = mountMenu(['u-2']);

        expect(label(wrapper)).toBe('Sven Support');
        expect(wrapper.find('[data-user-avatar]').exists()).toBe(true);
    });

    it('counts instead of naming as soon as there are several', () => {
        const wrapper = mountMenu(['u-1', 'u-3']);

        expect(label(wrapper)).toBe('2 Mitwirkende');
        expect(label(wrapper)).not.toContain('Anna Albers');
    });

    it('still offers every name inside the menu', () => {
        const wrapper = mountMenu(['u-1', 'u-3']);
        const items = wrapper
            .findAll('[data-multi-select-item]')
            .map((item) => item.text());

        expect(items).toEqual([
            expect.stringContaining('Anna Albers'),
            expect.stringContaining('Sven Support'),
            expect.stringContaining('Rita Vertrieb'),
        ]);
    });

    it('passes the new selection on', async () => {
        const wrapper = mountMenu(['u-1']);

        await wrapper.findAll('[data-multi-select-item]')[1].trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([
            [['u-1', 'u-2']],
        ]);
    });
});
