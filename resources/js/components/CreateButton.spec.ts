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
});
