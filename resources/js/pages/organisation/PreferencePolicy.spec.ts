import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import PreferencePolicyController from '@/actions/App/Http/Controllers/Tenancy/PreferencePolicyController';
import PreferencePolicy from '@/pages/organisation/PreferencePolicy.vue';
import type { PreferencePolicy as Policy } from '@/types/preferences';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const put = vi.fn();
const visit = vi.hoisted(() => vi.fn());
const transforms: Array<() => { preference_policy: Policy }> = [];

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    useForm: () => ({
        processing: false,
        transform: (callback: () => { preference_policy: Policy }) => {
            transforms.push(callback);

            return { put };
        },
    }),
    router: { on: vi.fn(() => vi.fn()), visit },
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
                    props: ['dirty', 'processing', 'cancellable'],
                    emits: ['save', 'cancel'],
                    template:
                        '<div data-form-actions :data-cancellable="cancellable"><button data-save @click="$emit(\'save\')" /><button data-cancel @click="$emit(\'cancel\')" /></div>',
                },
                UnsavedChangesDialog: { template: '<div />' },
            },
        },
    });
}

describe('PreferencePolicy — grouped by the area it governs', () => {
    it('renders one section per area inside a single card', () => {
        const wrapper = mountPage();

        expect(
            wrapper
                .findAll('[data-policy-area]')
                .map((card) => card.attributes('data-policy-area')),
        ).toEqual(['records', 'configuration', 'global']);
        expect(wrapper.findAll('[data-slot="card"]')).toHaveLength(1);
        expect(
            wrapper
                .findAll('[data-policy-area]')
                .every((area) => area.attributes('data-card-section') === ''),
        ).toBe(true);
    });

    it('renders one save row at the end of the card, with cancel', () => {
        const wrapper = mountPage();

        const rows = wrapper.findAll('[data-form-actions]');

        expect(rows).toHaveLength(1);
        expect(rows[0].attributes('data-cancellable')).toBeUndefined();
        expect(
            wrapper
                .get('[data-slot="card"]')
                .find('[data-form-actions]')
                .exists(),
        ).toBe(true);
    });

    it('cancels back to the page without a prompt while nothing changed', async () => {
        visit.mockClear();

        const wrapper = mountPage();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(visit).toHaveBeenCalledWith(
            PreferencePolicyController.edit.url(),
        );
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
        expect(wrapper.find('[data-policy-read-only]').exists()).toBe(true);
    });

    it('shows no read-only explanation to a user who may change the policy', () => {
        expect(mountPage().find('[data-policy-read-only]').exists()).toBe(
            false,
        );
    });

    it('sends the switched matrix on save', async () => {
        const wrapper = mountPage();

        await wrapper
            .get('[data-policy-switch="viewMode.records"]')
            .trigger('click');
        await wrapper.get('[data-save]').trigger('click');

        expect(put).toHaveBeenCalledWith(
            PreferencePolicyController.update.url(),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(transforms.at(-1)?.().preference_policy.viewMode?.records).toBe(
            false,
        );
        expect(
            wrapper
                .get('[data-policy-switch="viewMode.records"]')
                .attributes('data-checked'),
        ).toBe('false');
    });
});
