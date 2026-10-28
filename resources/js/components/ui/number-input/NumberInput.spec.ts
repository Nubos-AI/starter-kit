import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { NumberInput } from '@/components/ui/number-input';
import { numberInputFormat } from '@/lib/localizedNumber';

function visibleInput(wrapper: ReturnType<typeof mount>) {
    return wrapper.get('[data-number-input]');
}

afterEach(() => {
    document.documentElement.lang = '';
});

describe('NumberInput', () => {
    it('shows the value in the notation of the language', () => {
        const wrapper = mount(NumberInput, {
            props: { modelValue: '23123.45', format: numberInputFormat('money') },
        });

        expect(
            (visibleInput(wrapper).element as HTMLInputElement).value,
        ).toBe('23.123,45');
    });

    it('shows the American notation in English', () => {
        document.documentElement.lang = 'en';

        const wrapper = mount(NumberInput, {
            props: { modelValue: 23123.45, format: numberInputFormat('money') },
        });

        expect(
            (visibleInput(wrapper).element as HTMLInputElement).value,
        ).toBe('23,123.45');
    });

    it('drops the grouping while the user edits', async () => {
        const wrapper = mount(NumberInput, {
            props: { modelValue: '23123.45', format: numberInputFormat('money') },
        });

        await visibleInput(wrapper).trigger('focus');

        expect(
            (visibleInput(wrapper).element as HTMLInputElement).value,
        ).toBe('23123,45');
    });

    it('hands the canonical value on when the user types the local notation', async () => {
        const wrapper = mount(NumberInput, {
            props: { modelValue: null, format: numberInputFormat('decimal') },
        });

        await visibleInput(wrapper).setValue('1.234,5');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '1234.5',
        ]);
    });

    it('opens a decimal keyboard instead of a native number field', () => {
        const wrapper = mount(NumberInput, {
            props: { modelValue: 1, format: numberInputFormat('decimal') },
        });

        expect(visibleInput(wrapper).attributes('type')).toBe('text');
        expect(visibleInput(wrapper).attributes('inputmode')).toBe('decimal');
    });

    it('submits the canonical value under the name, not the local notation', () => {
        const wrapper = mount(NumberInput, {
            props: {
                modelValue: '1234.5',
                format: numberInputFormat('money'),
                name: 'amount',
            },
        });

        expect(wrapper.get('input[name="amount"]').attributes('type')).toBe(
            'hidden',
        );
        expect(
            (wrapper.get('input[name="amount"]').element as HTMLInputElement)
                .value,
        ).toBe('1234.5');
        expect(visibleInput(wrapper).attributes('name')).toBeUndefined();
    });
});
