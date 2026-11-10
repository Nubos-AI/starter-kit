import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SalutationSelect from '@/components/SalutationSelect.vue';
import { selectStubs } from '@/tests/selectStubs';
import type { SelectOption } from '@/types/ui';

const options: SelectOption[] = [
    { value: 'mr', label: 'Herr' },
    { value: 'ms', label: 'Frau' },
];

type Wrapper = ReturnType<typeof mount>;

function mountSelect(props: Record<string, unknown> = {}): Wrapper {
    return mount(SalutationSelect, {
        props: { id: 'salutation', options, modelValue: 'mr', ...props },
        global: { stubs: selectStubs },
    });
}

describe('SalutationSelect', () => {
    it('submits under the salutation name and offers every option', () => {
        const wrapper = mountSelect();
        const select = wrapper.get('select');

        expect(select.attributes('name')).toBe('salutation');
        expect(
            wrapper.findAll('option').map((option) => option.text()),
        ).toEqual(['Herr', 'Frau']);
    });

    it('binds the trigger to the id the label points at', () => {
        const trigger = mountSelect({ id: 'user-salutation' }).get(
            '[data-salutation-select]',
        );

        expect(trigger.attributes('id')).toBe('user-salutation');
    });

    it('reports the chosen salutation through the model', async () => {
        const wrapper = mountSelect();

        await wrapper.get('select').setValue('ms');

        expect(wrapper.emitted('update:modelValue')).toEqual([['ms']]);
    });

    it('marks the trigger invalid only while the field has an error', () => {
        expect(
            mountSelect()
                .get('[data-salutation-select]')
                .attributes('aria-invalid'),
        ).toBeUndefined();
        expect(
            mountSelect({ invalid: true })
                .get('[data-salutation-select]')
                .attributes('aria-invalid'),
        ).toBe('true');
    });
});
