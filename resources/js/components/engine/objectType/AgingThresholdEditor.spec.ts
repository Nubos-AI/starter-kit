import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { PropType } from 'vue';
import { defineComponent, ref } from 'vue';
import AgingThresholdEditor from '@/components/engine/objectType/AgingThresholdEditor.vue';
import { AGING_THRESHOLD_COLOR } from '@/lib/statusMaps';
import { selectStubs } from '@/tests/selectStubs';
import type { AgingThreshold } from '@/types/aging';

const THRESHOLD_DUPLICATE_MESSAGE =
    'Zwei Stufen dürfen nicht dieselbe Dauer haben.';

const thresholdPair: AgingThreshold[] = [
    { after_days: 7, color: 'amber' },
    { after_days: 30, color: 'red' },
];

const ThresholdHost = defineComponent({
    name: 'ThresholdHost',
    components: { AgingThresholdEditor },
    props: {
        initial: {
            type: Array as PropType<AgingThreshold[]>,
            required: true,
        },
        errors: {
            type: Object as PropType<Record<string, string>>,
            default: () => ({}),
        },
    },
    setup(hostProps) {
        const model = ref<AgingThreshold[]>(
            hostProps.initial.map((threshold) => ({ ...threshold })),
        );

        return { model };
    },
    template: '<AgingThresholdEditor v-model="model" :errors="errors" />',
});

function mountThresholdEditor(
    initial: AgingThreshold[] = thresholdPair,
    errors: Record<string, string> = {},
): VueWrapper {
    return mount(ThresholdHost, {
        props: { initial, errors },
        global: { stubs: { ...selectStubs } },
    });
}

function thresholdModel(wrapper: VueWrapper): AgingThreshold[] {
    return (wrapper.vm as unknown as { model: AgingThreshold[] }).model;
}

function thresholdRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
    return wrapper.findAll('[data-aging-threshold-row]');
}

function thresholdDuration(
    wrapper: VueWrapper,
    index: number,
): Omit<DOMWrapper<Element>, 'exists'> {
    return thresholdRows(wrapper)[index].get('input[type="number"]');
}

function thresholdColor(
    wrapper: VueWrapper,
    index: number,
): Omit<DOMWrapper<HTMLSelectElement>, 'exists'> {
    return thresholdRows(wrapper)[index].get<HTMLSelectElement>(
        'select.ui-select',
    );
}

describe('AgingThresholdEditor', () => {
    it('renders one row per threshold with a duration field and a severity select', () => {
        const wrapper = mountThresholdEditor();

        expect(thresholdRows(wrapper)).toHaveLength(2);
        expect(
            (thresholdDuration(wrapper, 0).element as HTMLInputElement).value,
        ).toBe('7');
        expect(thresholdColor(wrapper, 0).element.value).toBe('amber');
        expect(
            (thresholdDuration(wrapper, 1).element as HTMLInputElement).value,
        ).toBe('30');
        expect(thresholdColor(wrapper, 1).element.value).toBe('red');

        const options = thresholdColor(wrapper, 0).findAll('option');

        expect(options.map((option) => option.attributes('value'))).toEqual([
            'amber',
            'red',
        ]);
        expect(options.map((option) => option.text())).toEqual([
            AGING_THRESHOLD_COLOR.amber.label,
            AGING_THRESHOLD_COLOR.red.label,
        ]);
        expect(AGING_THRESHOLD_COLOR.amber.label).toBe('Warnung');
        expect(AGING_THRESHOLD_COLOR.red.label).toBe('Kritisch');
    });

    it('appends a row and hands the grown list to the parent', async () => {
        const wrapper = mountThresholdEditor();

        await wrapper.get('[data-aging-threshold-add]').trigger('click');

        expect(thresholdRows(wrapper)).toHaveLength(3);
        expect(thresholdModel(wrapper)).toHaveLength(3);
        expect(thresholdModel(wrapper)[0]).toEqual({
            after_days: 7,
            color: 'amber',
        });
        expect(thresholdModel(wrapper)[1]).toEqual({
            after_days: 30,
            color: 'red',
        });
    });

    it('drops exactly the removed threshold and keeps the others', async () => {
        const wrapper = mountThresholdEditor([
            { after_days: 7, color: 'amber' },
            { after_days: 14, color: 'amber' },
            { after_days: 30, color: 'red' },
        ]);

        await thresholdRows(wrapper)[1]
            .get('[data-aging-threshold-remove]')
            .trigger('click');

        expect(thresholdModel(wrapper)).toEqual([
            { after_days: 7, color: 'amber' },
            { after_days: 30, color: 'red' },
        ]);
        expect(thresholdRows(wrapper)).toHaveLength(2);
    });

    it('disables the remove button of the last remaining row instead of hiding it', () => {
        const single = mountThresholdEditor([
            { after_days: 7, color: 'amber' },
        ]);
        const removeButtons = single.findAll('[data-aging-threshold-remove]');

        expect(removeButtons).toHaveLength(1);
        expect(removeButtons[0].attributes('disabled')).toBeDefined();

        const pair = mountThresholdEditor();

        expect(
            pair
                .findAll('[data-aging-threshold-remove]')
                .map((button) => button.attributes('disabled')),
        ).toEqual([undefined, undefined]);
    });

    it('names the clash when two rows share a duration and stays silent otherwise', async () => {
        const wrapper = mountThresholdEditor();

        expect(wrapper.find('[data-aging-threshold-duplicate]').exists()).toBe(
            false,
        );

        await thresholdDuration(wrapper, 1).setValue('7');

        expect(wrapper.get('[data-aging-threshold-duplicate]').text()).toBe(
            THRESHOLD_DUPLICATE_MESSAGE,
        );

        await thresholdDuration(wrapper, 1).setValue('30');

        expect(wrapper.find('[data-aging-threshold-duplicate]').exists()).toBe(
            false,
        );
    });

    it('hands a typed duration to the parent as a number, never as a string', async () => {
        const wrapper = mountThresholdEditor();

        await thresholdDuration(wrapper, 0).setValue('14');

        expect(thresholdModel(wrapper)[0].after_days).toBe(14);
        expect(typeof thresholdModel(wrapper)[0].after_days).toBe('number');
        expect(thresholdModel(wrapper)[0].color).toBe('amber');
    });

    it('renders a server error for a single row next to that row alone', () => {
        const wrapper = mountThresholdEditor(thresholdPair, {
            'thresholds.0.after_days': 'FEHLER_DAUER',
        });

        expect(thresholdRows(wrapper)[0].text()).toContain('FEHLER_DAUER');
        expect(thresholdRows(wrapper)[1].text()).not.toContain('FEHLER_DAUER');
    });
});
