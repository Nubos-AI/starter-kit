import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import NumberCellEditor from '@/components/engine/NumberCellEditor.vue';
import { numberInputFormat } from '@/lib/localizedNumber';

function mountEditor(value: unknown) {
    return mount(NumberCellEditor, {
        attachTo: document.body,
        props: { params: { value, format: numberInputFormat('money') } },
    });
}

describe('NumberCellEditor', () => {
    it('opens focused with the value in the notation of the language', async () => {
        const wrapper = mountEditor('23123.45');

        await wrapper.vm.$nextTick();

        expect(
            (wrapper.get('[data-number-input]').element as HTMLInputElement)
                .value,
        ).toBe('23123,45');
    });

    it('hands the grid the canonical value of what the user typed', async () => {
        const wrapper = mountEditor('1');

        await wrapper.get('[data-number-input]').setValue('1.234,5');

        expect(
            (wrapper.vm as unknown as { getValue(): unknown }).getValue(),
        ).toBe('1234.5');
    });

    it('hands the grid null once the user cleared the cell', async () => {
        const wrapper = mountEditor('1');

        await wrapper.get('[data-number-input]').setValue('');

        expect(
            (wrapper.vm as unknown as { getValue(): unknown }).getValue(),
        ).toBeNull();
    });
});
