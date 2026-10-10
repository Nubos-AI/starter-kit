import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FormulaErrorCell from '@/components/engine/FormulaErrorCell.vue';
import type { FormulaErrorCode } from '@/types/formulas';
import { formulaErrorLabels } from '@/types/formulas';

const errorCodes = Object.keys(formulaErrorLabels) as FormulaErrorCode[];

function mountValue(value: unknown): ReturnType<typeof mount> {
    return mount(FormulaErrorCell, { props: { value } });
}

function errorMarker(
    wrapper: ReturnType<typeof mount>,
): ReturnType<ReturnType<typeof mount>['find']> {
    return wrapper.find('[data-formula-error]');
}

function accessibleName(wrapper: ReturnType<typeof mount>): string {
    const marker = errorMarker(wrapper);

    if (!marker.exists()) {
        return '';
    }

    return marker.attributes('aria-label') ?? marker.attributes('title') ?? '';
}

describe('FormulaErrorCell — persisted error values', () => {
    it('covers every error code the frozen contract defines', () => {
        expect(errorCodes).toHaveLength(8);
    });

    it.each(errorCodes)('names the cause of a %s error value', (code) => {
        const wrapper = mountValue({ code, field_key: null });

        expect(wrapper.text()).toContain(formulaErrorLabels[code]);
    });

    it.each(errorCodes)(
        'renders neither a raw object nor an empty cell for %s',
        (code) => {
            const wrapper = mountValue({ code, field_key: 'gross' });

            expect(wrapper.text()).not.toContain('[object Object]');
            expect(wrapper.html()).not.toContain('[object Object]');
            expect(wrapper.text().trim()).not.toBe('');
        },
    );

    it('marks the error state with the stable data attribute carrying the code', () => {
        const wrapper = mountValue({
            code: 'division_by_zero',
            field_key: null,
        });

        const marker = errorMarker(wrapper);

        expect(marker.exists()).toBe(true);
        expect(marker.attributes('data-formula-error')).toBe(
            'division_by_zero',
        );
    });

    it('gives the error state an accessible name that names the offending field key', () => {
        const wrapper = mountValue({
            code: 'unknown_field_reference',
            field_key: 'net_amount',
        });

        const name = accessibleName(wrapper);

        expect(name.trim()).not.toBe('');
        expect(name).toContain('net_amount');
    });

    it('still gives the error state an accessible name when no field key is recorded', () => {
        const wrapper = mountValue({
            code: 'evaluation_limit_exceeded',
            field_key: null,
        });

        const name = accessibleName(wrapper);

        expect(name.trim()).not.toBe('');
        expect(name).toContain(formulaErrorLabels.evaluation_limit_exceeded);
    });
});

describe('FormulaErrorCell — ordinary values', () => {
    it.each([
        [119, '119'],
        ['abc', 'abc'],
        [true, 'true'],
        [0, '0'],
        [false, 'false'],
    ])(
        'renders the scalar %s as readable text without an error marker',
        (value, expected) => {
            const wrapper = mountValue(value);

            expect(wrapper.text()).toContain(expected);
            expect(errorMarker(wrapper).exists()).toBe(false);
        },
    );

    it.each([[null], [undefined], ['']])(
        'renders %s as an empty cell without an error marker',
        (value) => {
            const wrapper = mountValue(value);

            expect(wrapper.text().trim()).toBe('');
            expect(wrapper.html()).not.toContain('[object Object]');
            expect(errorMarker(wrapper).exists()).toBe(false);
        },
    );

    it('joins an array value instead of stringifying it', () => {
        const wrapper = mountValue(['alpha', 'beta']);

        expect(wrapper.text()).toContain('alpha, beta');
        expect(wrapper.text()).not.toContain('[object Object]');
        expect(errorMarker(wrapper).exists()).toBe(false);
    });

    it('renders a non-error object readably rather than as [object Object]', () => {
        const wrapper = mountValue({ foo: 1 });

        expect(wrapper.text()).not.toContain('[object Object]');
        expect(wrapper.html()).not.toContain('[object Object]');
        expect(wrapper.text()).toContain('foo');
        expect(errorMarker(wrapper).exists()).toBe(false);
    });

    it('treats an object with an unknown code as an ordinary value, not as an error', () => {
        const wrapper = mountValue({
            code: 'not_a_real_code',
            field_key: null,
        });

        expect(errorMarker(wrapper).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('[object Object]');
    });
});
