import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FormSwitch from '@/components/FormSwitch.vue';

const SwitchStub = {
    props: ['id', 'modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
        '<button type="button" data-switch :id="id" :data-checked="modelValue" :disabled="disabled" @click="$emit(\'update:modelValue\', !modelValue)" />',
};

function mountSwitch(
    props: Record<string, unknown> = {},
    attrs: Record<string, unknown> = {},
): VueWrapper {
    return mount(FormSwitch, {
        props: { label: 'Aktiv', modelValue: false, ...props },
        attrs,
        global: { stubs: { Switch: SwitchStub } },
    });
}

describe('FormSwitch', () => {
    it('emits the toggled value through v-model', async () => {
        const wrapper = mountSwitch();

        await wrapper.get('[data-switch]').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([[true]]);
    });

    it('ties the label to the switch through the given id', () => {
        const wrapper = mountSwitch({ id: 'is_enabled' });

        expect(wrapper.get('label').attributes('for')).toBe('is_enabled');
        expect(wrapper.get('[data-switch]').attributes('id')).toBe(
            'is_enabled',
        );
    });

    it('generates an id when none is given', () => {
        const wrapper = mountSwitch();

        const controlId = wrapper.get('[data-switch]').attributes('id');

        expect(controlId).toBeTruthy();
        expect(wrapper.get('label').attributes('for')).toBe(controlId);
    });

    it('disables the switch', () => {
        const wrapper = mountSwitch({ disabled: true });

        expect(
            wrapper.get('[data-switch]').attributes('disabled'),
        ).toBeDefined();
    });

    it('renders the hint only when one is given', () => {
        expect(
            mountSwitch().find('[data-form-switch-description]').exists(),
        ).toBe(false);

        expect(
            mountSwitch({ description: 'Gilt für alle Datensätze.' })
                .get('[data-form-switch-description]')
                .text(),
        ).toBe('Gilt für alle Datensätze.');
    });

    it('shows the bound error message', () => {
        const wrapper = mountSwitch({ error: 'Bitte prüfen Sie die Angabe.' });

        expect(wrapper.text()).toContain('Bitte prüfen Sie die Angabe.');
    });

    it('forwards the caller hooks to the switch instead of the wrapper', () => {
        const wrapper = mountSwitch({}, { 'data-policy-switch': 'records' });

        expect(
            wrapper.get('[data-switch]').attributes('data-policy-switch'),
        ).toBe('records');
        expect(
            wrapper.get('[data-form-switch]').attributes('data-policy-switch'),
        ).toBeUndefined();
    });
});
