import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AppSidebar from '@/components/AppSidebar.vue';
import NavDrillDown from '@/components/NavDrillDown.vue';

const { navigation } = vi.hoisted(() => ({
    navigation: {
        main: [
            {
                label: '',
                items: [
                    {
                        key: 'dashboard',
                        label: 'Dashboard',
                        icon: 'layout-grid',
                        href: '/dashboard',
                    },
                ],
            },
            {
                label: 'Records',
                items: [
                    {
                        key: 'record-companies',
                        label: 'Companies',
                        icon: 'database',
                        href: '/records/companies',
                    },
                ],
            },
        ],
        configuration: [
            {
                key: 'configuration',
                label: 'Konfiguration',
                icon: 'settings2',
                children: [
                    {
                        key: 'automations',
                        label: 'Automationen',
                        icon: 'waypoints',
                        href: '/engine/automations',
                    },
                ],
            },
        ],
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { navigation } }),
    Link: { template: '<a><slot /></a>' },
}));

describe('AppSidebar', () => {
    it('passes the main sections and configuration tree into the sidebar nav', () => {
        const wrapper = shallowMount(AppSidebar, {
            global: { renderStubDefaultSlot: true },
        });

        const drill = wrapper.findComponent(NavDrillDown);
        expect(drill.exists()).toBe(true);
        expect(drill.props('mainSections')).toEqual(navigation.main);
        expect(drill.props('configuration')).toEqual(navigation.configuration);
    });
});
