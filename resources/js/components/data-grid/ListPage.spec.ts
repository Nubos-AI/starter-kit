import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ListPage from '@/components/data-grid/ListPage.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        props: ['title'],
        template: '<head-stub :data-head-title="title" />',
    },
}));

const stubs = { UiExtensionPoint: true };

describe('ListPage', () => {
    it('renders the heading, the page title and the actions slot when a title is set', () => {
        const wrapper = mount(ListPage, {
            props: { title: 'Fähigkeiten', description: 'Alle Fähigkeiten' },
            slots: {
                actions: '<button data-list-action>Anlegen</button>',
                default: '<div data-list-body />',
            },
            global: { stubs },
        });

        const header = wrapper.get('[data-list-page-header]');

        expect(header.text()).toContain('Fähigkeiten');
        expect(header.text()).toContain('Alle Fähigkeiten');
        expect(header.find('[data-list-action]').exists()).toBe(true);
        expect(
            wrapper.get('[data-head-title]').attributes('data-head-title'),
        ).toBe('Fähigkeiten');
        expect(wrapper.find('[data-list-body]').exists()).toBe(true);
    });

    it('leaves the header to the page when no title is set', () => {
        const wrapper = mount(ListPage, {
            slots: { default: '<div data-list-body />' },
            global: { stubs },
        });

        expect(wrapper.find('[data-list-page-header]').exists()).toBe(false);
        expect(wrapper.find('[data-head-title]').exists()).toBe(false);
        expect(wrapper.find('[data-list-body]').exists()).toBe(true);
    });
});
