import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ErrorState from '@/components/engine/state/ErrorState.vue';

describe('ErrorState', () => {
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
});
