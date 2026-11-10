import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReasonedButton from '@/components/ReasonedButton.vue';

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    TooltipProvider: passthrough,
    Tooltip: passthrough,
    TooltipTrigger: passthrough,
    TooltipContent: passthrough,
};

function mountButton(
    props: Record<string, unknown> = {},
    attrs: Record<string, unknown> = {},
): VueWrapper {
    return mount(ReasonedButton, {
        props: { reason: null, ...props },
        attrs: { 'data-start': '', ...attrs },
        slots: { default: 'Starten' },
        global: { stubs },
    });
}

describe('ReasonedButton', () => {
    it('emits click while no reason blocks it', async () => {
        const wrapper = mountButton();

        await wrapper.get('[data-start]').trigger('click');

        expect(wrapper.emitted('click')).toHaveLength(1);
        expect(wrapper.find('[data-reasoned-button-reason]').exists()).toBe(
            false,
        );
    });

    it('disables the button and names the reason in a tooltip', async () => {
        const wrapper = mountButton({
            reason: 'Ihnen fehlt die Berechtigung.',
        });

        const button = wrapper.get('[data-start]');

        expect(button.attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-reasoned-button-reason]').text()).toBe(
            'Ihnen fehlt die Berechtigung.',
        );

        await button.trigger('click');

        expect(wrapper.emitted('click')).toBeUndefined();
    });

    it('keeps the disabled button reachable by keyboard through its wrapper', () => {
        const wrapper = mountButton({ reason: 'Gesperrt.' });

        expect(
            wrapper
                .get('[data-reasoned-button-trigger]')
                .attributes('tabindex'),
        ).toBe('0');
    });

    it('stays disabled without a reason when the caller disables it', () => {
        const wrapper = mountButton({ disabled: true });

        expect(
            wrapper.get('[data-start]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.find('[data-reasoned-button-reason]').exists()).toBe(
            false,
        );
    });

    it('passes variant and type through to the button', () => {
        const wrapper = mountButton({ variant: 'outline', type: 'submit' });

        const button = wrapper.get('[data-start]');

        expect(button.attributes('data-variant')).toBe('outline');
        expect(button.attributes('type')).toBe('submit');
        expect(button.text()).toBe('Starten');
    });
});
