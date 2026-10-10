import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import NavDrillDown from '@/components/NavDrillDown.vue';
import type { NavNode, NavSection } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
}));

vi.mock('@/composables/useCurrentUrl', () => ({
    useCurrentUrl: () => ({ isCurrentUrl: () => false }),
}));

vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: { template: '<div><slot /></div>' },
    SidebarGroupLabel: { template: '<div><slot /></div>' },
    SidebarMenu: { template: '<ul><slot /></ul>' },
    SidebarMenuItem: { template: '<li><slot /></li>' },
    SidebarMenuButton: { template: '<button><slot /></button>' },
}));

const mainSections: NavSection[] = [
    {
        label: '',
        items: [
            { key: 'dashboard', label: 'Dashboard', href: '/dashboard' },
            { key: 'reminders', label: 'Reminders', href: '/reminders' },
        ],
    },
    {
        label: 'Records',
        items: [{ key: 'c', label: 'Companies', href: '/records/companies' }],
    },
];

const configuration: NavNode[] = [
    {
        key: 'configuration',
        label: 'Konfiguration',
        children: [
            {
                key: 'automations',
                label: 'Automationen',
                href: '/engine/automations',
            },
            {
                key: 'object-types',
                label: 'Object types',
                href: '/engine/object-types',
            },
            {
                key: 'group',
                label: 'Untergruppe',
                children: [{ key: 'deep', label: 'Tief', href: '/deep' }],
            },
        ],
    },
];

function mountNav() {
    return mount(NavDrillDown, { props: { mainSections, configuration } });
}

async function clickText(
    wrapper: ReturnType<typeof mountNav>,
    text: string,
): Promise<void> {
    const button = wrapper
        .findAll('button')
        .find((candidate) => candidate.text().includes(text));

    if (!button) {
        throw new Error(`No button containing "${text}"`);
    }

    await button.trigger('click');
    await nextTick();
}

describe('NavDrillDown', () => {
    it('shows the flat main sections plus a configuration entry at root', () => {
        const wrapper = mountNav();

        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Reminders');
        expect(wrapper.text()).toContain('Companies');
        expect(wrapper.text()).toContain('Konfiguration');
        expect(wrapper.text()).not.toContain('Automationen');
        expect(wrapper.text()).not.toContain('Zurück');
    });

    it('drills into configuration, replacing the main area with its children', async () => {
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');

        expect(wrapper.text()).toContain('Automationen');
        expect(wrapper.text()).toContain('Object types');
        expect(wrapper.text()).toContain('Zurück');
        expect(wrapper.text()).not.toContain('Dashboard');
        expect(wrapper.text()).not.toContain('Companies');
    });

    it('drills arbitrarily deep', async () => {
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');
        await clickText(wrapper, 'Untergruppe');

        expect(wrapper.text()).toContain('Tief');
        expect(wrapper.text()).toContain('Zurück');
        expect(wrapper.text()).not.toContain('Automationen');
    });

    it('returns to the root level via the back row', async () => {
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');
        await clickText(wrapper, 'Zurück');

        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Konfiguration');
        expect(wrapper.text()).not.toContain('Automationen');
    });

    it('renders links for the flat leaf items', () => {
        const wrapper = mountNav();

        expect(wrapper.find('a').exists()).toBe(true);
    });
});
