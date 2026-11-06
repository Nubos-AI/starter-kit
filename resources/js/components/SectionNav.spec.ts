import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import SectionNav from '@/components/SectionNav.vue';
import type { SectionNavEntry } from '@/types/navigation';

const { mocks } = vi.hoisted(() => ({
    mocks: {} as { page: { url: string; props: Record<string, unknown> } },
}));

vi.mock('@inertiajs/vue3', () => {
    mocks.page = reactive({ url: '/settings/general', props: {} });

    return {
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => mocks.page,
    };
});

const LINK_ENTRIES: SectionNavEntry[] = [
    { key: 'general', title: 'Allgemein', href: '/settings/general' },
    { key: 'fields', title: 'Felder', href: '/settings/fields' },
    {
        key: 'rules',
        title: 'Regeln',
        href: null,
        disabledReason: 'Erst nach dem Speichern verfügbar.',
    },
];

const TAB_ENTRIES: SectionNavEntry[] = [
    { key: 'basics', title: 'Grunddaten' },
    { key: 'schedule', title: 'Zeitplan', hasErrors: true },
];

function mountNav(
    entries: SectionNavEntry[],
    modelValue: string | null = null,
) {
    return mount(SectionNav, {
        props: {
            entries,
            label: 'Abschnitte',
            modelValue,
        },
        attrs: { 'data-caller-hook': '' },
        slots: {
            default: '<p data-section-content>Inhalt</p>',
            append: '<span data-appended-entry>Zusatz</span>',
        },
    });
}

beforeEach(() => {
    mocks.page.url = '/settings/general';
});

describe('SectionNav', () => {
    it('renders entries with a target as links and marks the current url', () => {
        const wrapper = mountNav(LINK_ENTRIES);

        const links = wrapper.findAll('[data-section-nav] a');

        expect(links.map((link) => link.text())).toEqual([
            'Allgemein',
            'Felder',
        ]);
        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/settings/general',
            '/settings/fields',
        ]);
        expect(
            wrapper
                .findAll('[data-section-active]')
                .map((entry) => entry.attributes('data-section-key')),
        ).toEqual(['general']);
    });

    it('disables an entry without a target that carries a reason', () => {
        const wrapper = mountNav(LINK_ENTRIES);

        const disabled = wrapper.get('[data-section-disabled]');

        expect(disabled.attributes('data-section-key')).toBe('rules');
        expect(disabled.attributes('disabled')).toBeDefined();
        expect(disabled.attributes('title')).toBe(
            'Erst nach dem Speichern verfügbar.',
        );
    });

    it('switches the active section through the model for entries without a target', async () => {
        const wrapper = mountNav(TAB_ENTRIES, 'basics');

        expect(
            wrapper.get('[data-section-active]').attributes('data-section-key'),
        ).toBe('basics');

        await wrapper.get('[data-section-key="schedule"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['schedule']]);
        expect(wrapper.find('[data-section-nav] a').exists()).toBe(false);
    });

    it('marks an entry with errors with a dot', () => {
        const wrapper = mountNav(TAB_ENTRIES, 'basics');

        expect(
            wrapper
                .findAll('[data-section-errors]')
                .map((entry) => entry.attributes('data-section-key')),
        ).toEqual(['schedule']);
        expect(
            wrapper
                .get('[data-section-key="schedule"]')
                .find('[data-section-error-dot]')
                .exists(),
        ).toBe(true);
        expect(
            wrapper
                .get('[data-section-key="basics"]')
                .find('[data-section-error-dot]')
                .exists(),
        ).toBe(false);
    });

    it('puts the hooks of the caller on the navigation and renders appended entries inside it', () => {
        const wrapper = mountNav(TAB_ENTRIES);

        const nav = wrapper.get('[data-section-nav]');

        expect(nav.attributes('data-caller-hook')).toBeDefined();
        expect(nav.attributes('aria-label')).toBe('Abschnitte');
        expect(nav.find('[data-appended-entry]').exists()).toBe(true);
        expect(wrapper.get('[data-section-content]').text()).toBe('Inhalt');
    });

    it('renders the content alone when there are no entries', () => {
        const wrapper = mountNav([]);

        expect(wrapper.find('[data-section-nav]').exists()).toBe(false);
        expect(wrapper.find('[data-caller-hook]').exists()).toBe(false);
        expect(wrapper.get('[data-section-content]').text()).toBe('Inhalt');
    });
});
