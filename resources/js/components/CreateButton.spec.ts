import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import CreateButton from '@/components/CreateButton.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href', 'only'],
        template: '<a :href="href"><slot /></a>',
    },
}));

describe('CreateButton', () => {
    it('renders a green create button with a leading plus icon by default', () => {
        const wrapper = mount(CreateButton, { props: { label: 'Anlegen' } });

        const button = wrapper.get('[data-create-button]');

        expect(button.attributes('data-variant')).toBe('create');
        expect(wrapper.text()).toContain('Anlegen');
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    it('carries the same bright green in light and dark mode', () => {
        const wrapper = mount(CreateButton, { props: { label: 'Anlegen' } });

        const classes = wrapper.get('[data-create-button]').classes();

        expect(classes).toContain('bg-success-solid');
        expect(classes).toContain('text-success-solid-foreground');
        expect(classes).not.toContain('bg-primary');
        expect(classes).not.toContain('bg-success-bold');
    });

    it('hides the icon when showIcon is false', () => {
        const wrapper = mount(CreateButton, {
            props: { label: 'Speichern', showIcon: false },
        });

        expect(wrapper.find('svg').exists()).toBe(false);
        expect(wrapper.text()).toContain('Speichern');
    });

    it('emits click in button mode', async () => {
        const wrapper = mount(CreateButton);

        await wrapper.get('[data-create-button]').trigger('click');

        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('renders an inertia link when href is set', () => {
        const wrapper = mount(CreateButton, {
            props: { href: '/object-types/create', label: 'New object type' },
        });

        const link = wrapper.get('a');

        expect(link.attributes('href')).toBe('/object-types/create');
        expect(link.text()).toContain('New object type');
    });

    it('renders a submit button when type is submit', () => {
        const wrapper = mount(CreateButton, {
            props: { type: 'submit', label: 'Create' },
        });

        expect(wrapper.get('[data-create-button]').attributes('type')).toBe(
            'submit',
        );
    });
});
