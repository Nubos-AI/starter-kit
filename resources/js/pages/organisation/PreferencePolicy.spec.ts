import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import PreferencePolicy from '@/pages/organisation/PreferencePolicy.vue';
import type { PreferencePolicy as Policy } from '@/types/preferences';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const put = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    useForm: () => ({
        processing: false,
        transform: () => ({ put }),
    }),
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => ({ url: '/nubos/engine/personalization', props: {} }),
}));

const categories = [
    {
        value: 'columnsAndSorting',
        label: 'Spalten & Sortierung',
        areas: ['records', 'configuration'],
    },
    { value: 'viewMode', label: 'Ansichtsmodus', areas: ['records'] },
    {
        value: 'filterAndSegment',
        label: 'Filter & Segment',
        areas: ['records'],
    },
    {
        value: 'layoutAndAppearance',
        label: 'Layout & Erscheinungsbild',
        areas: ['global'],
    },
    {
        value: 'panelState',
        label: 'Auf- und zugeklappte Bereiche',
        areas: ['records'],
    },
    {
        value: 'panelVisibility',
        label: 'Ausgeblendete Kästen',
        areas: ['records'],
    },
];

const policy: Policy = {
    columnsAndSorting: { records: true, configuration: false },
    viewMode: { records: true },
    filterAndSegment: { records: false },
    layoutAndAppearance: { global: true },
    panelState: { records: true },
    panelVisibility: { records: true },
};

const SwitchStub = {
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
        '<button type="button" :data-checked="modelValue" :disabled="disabled" @click="$emit(\'update:modelValue\', !modelValue)" />',
};

function mountPage(
    props: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(PreferencePolicy, {
        props: { policy, categories, canUpdate: true, ...props },
        global: {
            stubs: {
                Switch: SwitchStub,
                FormActions: {
                    name: 'FormActions',
                    props: ['dirty', 'processing', 'cancellable'],
                    emits: ['save', 'cancel'],
                    template:
                        '<div data-form-actions><button data-save @click="$emit(\'save\')" /></div>',
                },
                UnsavedChangesDialog: { template: '<div />' },
            },
        },
    });
}

describe('PreferencePolicy — the action row', () => {
    it('offers no cancel, because the page has no list to return to', () => {
        const wrapper = mountPage();

        expect(
            wrapper.findComponent({ name: 'FormActions' }).props('cancellable'),
        ).toBe(false);
    });
});

describe('PreferencePolicy — grouped by the area it governs', () => {
    it('renders one card per area instead of one per category', () => {
        const wrapper = mountPage();

        expect(
            wrapper
                .findAll('[data-policy-area]')
                .map((card) => card.attributes('data-policy-area')),
        ).toEqual(['records', 'configuration', 'global']);
    });

    it('lists every category of an area inside its card', () => {
        const wrapper = mountPage();

        const records = wrapper.get('[data-policy-area="records"]');

        expect(
            records
                .findAll('[data-policy-switch]')
                .map((entry) => entry.attributes('data-policy-switch')),
        ).toEqual([
            'columnsAndSorting.records',
            'viewMode.records',
            'filterAndSegment.records',
            'panelState.records',
            'panelVisibility.records',
        ]);
    });

    it('seeds every switch from the resolved policy', () => {
        const wrapper = mountPage();

        expect(
            wrapper
                .get('[data-policy-switch="columnsAndSorting.configuration"]')
                .attributes('data-checked'),
        ).toBe('false');
        expect(
            wrapper
                .get('[data-policy-switch="viewMode.records"]')
                .attributes('data-checked'),
        ).toBe('true');
    });

    it('disables every switch for a user who may only look', () => {
        const wrapper = mountPage({ canUpdate: false });

        expect(
            wrapper
                .findAll('[data-policy-switch]')
                .every((entry) => entry.attributes('disabled') !== undefined),
        ).toBe(true);
        expect(wrapper.find('[data-form-actions]').exists()).toBe(false);
    });

    it('sends the switched matrix on save', async () => {
        const wrapper = mountPage();

        await wrapper
            .get('[data-policy-switch="viewMode.records"]')
            .trigger('click');
        await wrapper.get('[data-save]').trigger('click');

        expect(put).toHaveBeenCalled();
        expect(
            wrapper
                .get('[data-policy-switch="viewMode.records"]')
                .attributes('data-checked'),
        ).toBe('false');
    });
});
