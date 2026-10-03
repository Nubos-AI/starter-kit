import { Inbox } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import EmptyState from '@/components/engine/state/EmptyState.vue';

describe('EmptyState', () => {
    it('renders the icon, title and description', () => {
        const wrapper = mount(EmptyState, {
            props: {
                icon: Inbox,
                title: 'Nichts da',
                description: 'Leer wie ein Sonntag.',
            },
        });

        expect(wrapper.text()).toContain('Nichts da');
        expect(wrapper.text()).toContain('Leer wie ein Sonntag.');
    });

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

    it('renders slotted call-to-action content', () => {
        const wrapper = mount(EmptyState, {
            props: { icon: Inbox, title: 'x', description: 'y' },
            slots: { default: '<button type="button">Anlegen</button>' },
        });

        expect(wrapper.find('button').text()).toBe('Anlegen');
    });
});
