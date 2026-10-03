import { mount } from '@vue/test-utils';
import { Pencil } from '@lucide/vue';
import { describe, expect, it, vi } from 'vitest';
import DeleteButton from '@/components/ui/icon-action-button/DeleteButton.vue';
import IconActionButton from '@/components/ui/icon-action-button/IconActionButton.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
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

    it('applies the danger intent for the destructive variant', () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Delete', variant: 'destructive' },
        });

        const classes = wrapper.get('button').classes();

        expect(classes).toContain('icon-danger');
        expect(classes).toContain('hover:icon-danger-hovered');
    });

    it('applies the information intent for the info variant', () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Verlauf', variant: 'info' },
        });

        const classes = wrapper.get('button').classes();

        expect(classes).toContain('icon-information');
        expect(classes).toContain('hover:icon-information-hovered');
    });

    it('applies the success intent for the edit variant', () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Bearbeiten', variant: 'edit' },
        });

        const classes = wrapper.get('button').classes();

        expect(classes).toContain('icon-success');
        expect(classes).toContain('hover:icon-success-hovered');
    });

    it('applies the success intent for the success variant', () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Aktivieren', variant: 'success' },
        });

        expect(wrapper.get('button').classes()).toContain('icon-success');
    });

    it('applies the warning intent for the warning variant', () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Fortsetzen', variant: 'warning' },
        });

        const classes = wrapper.get('button').classes();

        expect(classes).toContain('icon-warning');
        expect(classes).toContain('hover:icon-warning-hovered');
    });

    it('darkens the neutral icon for the default variant', () => {
        const wrapper = mount(IconActionButton, {
            props: { icon: Pencil, label: 'Ansehen' },
        });

        const classes = wrapper.get('button').classes();

        expect(classes).toContain('icon-subtlest');
        expect(classes).toContain('hover:icon-subtle');
    });

    it('never paints a hover background behind the icon', () => {
        const variants = [
            undefined,
            'destructive',
            'edit',
            'info',
            'success',
            'warning',
        ] as const;

        for (const variant of variants) {
            const wrapper = mount(IconActionButton, {
                props: { icon: Pencil, label: 'Aktion', variant },
            });

            const background = wrapper
                .get('button')
                .classes()
                .filter((name) => name.includes('bg-') && !name.startsWith('disabled:'));

            expect(background).toEqual([]);
        }
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
    it('renders a red trash button labelled Löschen by default', () => {
        const wrapper = mount(DeleteButton);

        const button = wrapper.get('button');

        expect(button.attributes('aria-label')).toBe('Löschen');
        expect(button.classes()).toContain('icon-danger');
        expect(wrapper.find('svg').exists()).toBe(true);
    });
});
