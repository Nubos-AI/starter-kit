import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ErrorState from '@/components/engine/state/ErrorState.vue';

describe('ErrorState', () => {
    it('renders the title and the default message', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Kaputt', defaultMessage: 'Standardfehler.' },
        });

        expect(wrapper.text()).toContain('Kaputt');
        expect(wrapper.text()).toContain('Standardfehler.');
    });

    it('prefers a provided message over the default', () => {
        const wrapper = mount(ErrorState, {
            props: {
                title: 'Kaputt',
                defaultMessage: 'Standardfehler.',
                message: 'Zeitüberschreitung.',
            },
        });

        expect(wrapper.text()).toContain('Zeitüberschreitung.');
        expect(wrapper.text()).not.toContain('Standardfehler.');
    });

    it('emits retry when the retry button is clicked', async () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Kaputt', defaultMessage: 'x' },
        });

        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
    });

    it('forwards attributes onto the retry button', () => {
        const wrapper = mount(ErrorState, {
            props: {
                title: 'Kaputt',
                defaultMessage: 'x',
                retryAttrs: { 'data-command-retry': '' },
            },
        });

        expect(wrapper.find('[data-command-retry]').exists()).toBe(true);
    });
});
