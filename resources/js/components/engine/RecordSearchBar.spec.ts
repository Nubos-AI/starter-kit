import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RecordSearchBar from '@/components/engine/RecordSearchBar.vue';

function mountBar(modelValue = '') {
    return mount(RecordSearchBar, { props: { modelValue } });
}

describe('RecordSearchBar', () => {
    it('reports every keystroke to its parent', async () => {
        const wrapper = mountBar();

        await wrapper.get('[data-record-search-input]').setValue('muster');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            'muster',
        ]);
    });

    it('offers a reset only while something is typed', async () => {
        const wrapper = mountBar();

        expect(
            wrapper.find('[data-testid="record-search-clear"]').exists(),
        ).toBe(false);

        await wrapper.setProps({ modelValue: 'muster' });

        expect(
            wrapper.find('[data-testid="record-search-clear"]').exists(),
        ).toBe(true);
    });

    it('asks its parent to reset when the reset is pressed', async () => {
        const wrapper = mountBar('muster');

        await wrapper
            .get('[data-testid="record-search-clear"]')
            .trigger('click');

        expect(wrapper.emitted('clear')).toHaveLength(1);
    });

    it('names the field for screen readers in German', () => {
        expect(
            mountBar()
                .get('[data-record-search-input]')
                .attributes('aria-label'),
        ).toBe('Datensätze durchsuchen');
    });
});
