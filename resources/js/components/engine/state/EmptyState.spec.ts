import { Inbox } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import EmptyState from '@/components/engine/state/EmptyState.vue';

describe('EmptyState', () => {
    it('renders the heading at the requested level', () => {
        const wrapper = mount(EmptyState, {
            props: {
                icon: Inbox,
                title: 'Nichts da',
                description: 'x',
                headingLevel: 'h3',
            },
        });

        expect(wrapper.find('h3').exists()).toBe(true);
        expect(wrapper.find('h2').exists()).toBe(false);
    });
});
