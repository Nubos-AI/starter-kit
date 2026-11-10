import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';
import Appearance from '@/pages/settings/Appearance.vue';
import { selectStubs } from '@/tests/selectStubs';

const { persistErrors } = vi.hoisted(() => ({
    persistErrors: { current: {} as Record<string, string> },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    usePage: () => ({ url: '/settings/appearance', props: {} }),
}));

vi.mock('@/composables/useUserPreferences', () => ({
    useUserPreferences: () => {
        const errors = ref<Record<string, string>>(persistErrors.current);

        return {
            settings: computed(() => ({
                appearance: 'system',
                density: 'compact',
                pageSize: 100,
                sidebarOpen: true,
                startObjectTypeId: null,
            })),
            patch: vi.fn(),
            errors: computed(() => errors.value),
        };
    },
}));

function mountPage() {
    return mount(Appearance, {
        props: { startPageOptions: [] },
        global: {
            stubs: { ...selectStubs, AppearanceTabs: { template: '<div />' } },
        },
    });
}

afterEach(() => {
    persistErrors.current = {};
});

describe('settings/Appearance', () => {
    it('labels the colour scheme group through its label id', () => {
        const wrapper = mountPage();

        expect(
            wrapper.get('[data-colour-scheme]').attributes('aria-labelledby'),
        ).toBe('appearance-colour-scheme');
        expect(wrapper.find('#appearance-colour-scheme').exists()).toBe(true);
    });

    it('binds a refused save to the field it belongs to', () => {
        persistErrors.current = {
            'settings.density': 'Die Dichte ist ungültig.',
            'settings.startObjectTypeId': 'Die Startseite gibt es nicht mehr.',
        };

        const text = mountPage().text();

        expect(text).toContain('Die Dichte ist ungültig.');
        expect(text).toContain('Die Startseite gibt es nicht mehr.');
    });
});
