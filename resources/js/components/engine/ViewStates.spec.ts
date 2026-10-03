import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axe } from 'vitest-axe';
import ViewStates from '@/components/engine/ViewStates.vue';
import type { RecordViewState } from '@/components/engine/ViewStates.vue';

const axeOptions = {
    runOnly: {
        type: 'tag' as const,
        values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
    },
    rules: { 'color-contrast': { enabled: false } },
};

function mountState(
    state: RecordViewState,
    props: Record<string, unknown> = {},
) {
    return mount(ViewStates, { props: { state, ...props } });
}

function buttonByText(wrapper: ReturnType<typeof mount>, text: string) {
    return wrapper.findAll('button').find((b) => b.text().includes(text));
}

describe('ViewStates — error state (SC-14)', () => {
    it('renders an alert with a retry button and emits retry on click', async () => {
        const wrapper = mountState('error');

        const alert = wrapper.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(wrapper.text()).toContain('Daten konnten nicht geladen werden');

        const retry = buttonByText(wrapper, 'Erneut versuchen');
        expect(retry).toBeDefined();
        await retry!.trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
    });

    it('surfaces a custom error message when provided', () => {
        const wrapper = mountState('error', {
            errorMessage: 'Zeitüberschreitung beim Laden.',
        });
        expect(wrapper.text()).toContain('Zeitüberschreitung beim Laden.');
    });
});

describe('ViewStates — empty states (SC-14)', () => {
    it('offers a create action for the no-records empty state when the view can create', async () => {
        const wrapper = mountState('empty', {
            emptyKind: 'no-records',
            onCreate: () => {},
        });

        expect(wrapper.text()).toContain('Noch keine Datensätze');
        const create = buttonByText(wrapper, 'Ersten Datensatz anlegen');
        expect(create).toBeDefined();
        await create!.trigger('click');
        expect(wrapper.emitted('create')).toHaveLength(1);
    });

    it('omits the create action when the view offers no way to create', () => {
        const wrapper = mountState('empty', { emptyKind: 'no-records' });

        expect(wrapper.text()).toContain('Noch keine Datensätze');
        expect(
            buttonByText(wrapper, 'Ersten Datensatz anlegen'),
        ).toBeUndefined();
    });

    it('offers a clear-filter action for the no-filter-match empty state', async () => {
        const wrapper = mountState('empty', { emptyKind: 'no-filter-match' });

        expect(wrapper.text()).toContain('Keine Treffer');
        const clear = buttonByText(wrapper, 'Filter zurücksetzen');
        expect(clear).toBeDefined();
        await clear!.trigger('click');
        expect(wrapper.emitted('clear-filter')).toHaveLength(1);
    });
});

describe('ViewStates — loading state (SC-14)', () => {
    it('renders an aria-hidden skeleton and no alert while loading', () => {
        const wrapper = mountState('loading');

        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('renders nothing once the view is ready', () => {
        const wrapper = mountState('ready');
        expect(wrapper.find('div').exists()).toBe(false);
    });
});

describe('ViewStates — accessibility (SC-14 / WCAG 2.1 AA)', () => {
    it('has no axe violations in the error state', async () => {
        const wrapper = mountState('error');
        const results = await axe(wrapper.element, axeOptions);
        expect(results.violations).toEqual([]);
    });

    it('has no axe violations in the empty state', async () => {
        const wrapper = mountState('empty', {
            emptyKind: 'no-records',
            onCreate: () => {},
        });
        const results = await axe(wrapper.element, axeOptions);
        expect(results.violations).toEqual([]);
    });
});

describe('ViewStates — an axis without columns explains itself', () => {
    it('names the missing axis values instead of claiming an empty column', () => {
        const wrapper = mountState('empty', { emptyKind: 'no-axis-columns' });

        expect(wrapper.text()).toContain('Keine Spalten für diese Achse');
        expect(wrapper.text()).toContain('Stages');
        expect(wrapper.text()).not.toContain('Leere Spalte');
    });

    it('offers no create button for an unconfigured axis', () => {
        const wrapper = mount(ViewStates, {
            props: { state: 'empty', emptyKind: 'no-axis-columns' },
            attrs: { onCreate: () => {} },
        });

        expect(
            buttonByText(wrapper, 'Ersten Datensatz anlegen'),
        ).toBeUndefined();
    });
});
