import { mount } from '@vue/test-utils';
import { Pencil } from '@lucide/vue';
import { describe, expect, it, vi } from 'vitest';
import DeleteButton from '@/shared/ui/primitives/icon-action-button/DeleteButton.vue';
import IconActionButton from '@/shared/ui/primitives/icon-action-button/IconActionButton.vue';
import { t } from '@/shared/testing/i18n';

vi.mock('@inertiajs/vue3', async () => ({
    Link: (await import('@/shared/testing/inertia')).linkStub,
}));

describe('IconActionButton', () => {
    it('renders an icon-only button with an accessible label', () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Bearbeiten' },
        });

        const button = wrapper.get('button');

        expect(button.attributes('aria-label')).toBe('Bearbeiten');
        expect(button.attributes('title')).toBe('Bearbeiten');
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    it('emits click when pressed', async () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Bearbeiten' },
        });

        await wrapper.get('button').trigger('click');

        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('renders a download anchor without the Inertia router when download is set', () => {
        const wrapper = mount(IconActionButton, {
            props: {
                icon: Pencil,
                label: 'Exportieren',
                href: '/export',
                download: true,
            },
        });

        const anchor = wrapper.get('a');

        expect(anchor.attributes('href')).toBe('/export');
        expect(anchor.attributes('download')).toBeDefined();
    });

    it('renders a link when href is provided', () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Verlauf', href: '/runs' },
        });

        expect(wrapper.get('a').attributes('href')).toBe('/runs');
    });

    it('turns a blocked link action into a disabled button carrying the reason', () => {
        const wrapper = mount(IconActionButton, {
            props: {
                icon: Pencil,
                label: 'Bearbeiten',
                href: '/records/1/edit',
                disabled: true,
                title: 'Keine Berechtigung zum Bearbeiten.',
            },
        });

        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
        expect(wrapper.get('button').attributes('title')).toBe(
            'Keine Berechtigung zum Bearbeiten.',
        );
    });

    it('turns a blocked download action into a disabled button', () => {
        const wrapper = mount(IconActionButton, {
            props: {
                icon: Pencil,
                label: 'Exportieren',
                href: '/export',
                download: true,
                disabled: true,
            },
        });

        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
    });
});

describe('DeleteButton', () => {
    it('labels the trash button with the default delete text when none is given', () => {
        const wrapper = mount(DeleteButton);

        const button = wrapper.get('button');

        expect(button.attributes('aria-label')).toBe(t('i18n.components.ui.icon_action_button.delete_button.delete'));
        expect(wrapper.find('svg').exists()).toBe(true);
    });
});
