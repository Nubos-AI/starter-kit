import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { expectNoAxeViolations } from '@/shared/testing/axe';
import { buttonContaining } from '@/shared/testing/dom';
import ViewStates from '@/shared/ui/ViewStates.vue';
import type { RecordViewState } from '@/shared/ui/ViewStates.vue';

function mountState(state: RecordViewState, props: Record<string, unknown> = {}) {
  return mount(ViewStates, { props: { state, ...props } });
}

describe('ViewStates — error state (SC-14)', () => {
  it('renders an alert with a retry button and emits retry on click', async () => {
    const wrapper = mountState('error');

    const alert = wrapper.find('[role="alert"]');
    expect(alert.exists()).toBe(true);
    expect(wrapper.text()).toContain('Daten konnten nicht geladen werden');

    const retry = buttonContaining(wrapper, 'Erneut versuchen');
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
    const create = buttonContaining(wrapper, 'Ersten Datensatz anlegen');
    expect(create).toBeDefined();
    await create!.trigger('click');
    expect(wrapper.emitted('create')).toHaveLength(1);
  });

  it('omits the create action when the view offers no way to create', () => {
    const wrapper = mountState('empty', { emptyKind: 'no-records' });

    expect(wrapper.text()).toContain('Noch keine Datensätze');
    expect(buttonContaining(wrapper, 'Ersten Datensatz anlegen')).toBeUndefined();
  });

  it('offers a clear-filter action for the no-filter-match empty state', async () => {
    const wrapper = mountState('empty', { emptyKind: 'no-filter-match' });

    expect(wrapper.text()).toContain('Keine Treffer');
    const clear = buttonContaining(wrapper, 'Filter zurücksetzen');
    expect(clear).toBeDefined();
    await clear!.trigger('click');
    expect(wrapper.emitted('clear-filter')).toHaveLength(1);
  });
});

describe('ViewStates — loading state (SC-14)', () => {
  it.each([
    ['loading', true],
    ['ready', false],
  ] as const)(
    'renders an aria-hidden skeleton only while loading and never an alert in the %s state',
    (state, isLoading) => {
      const wrapper = mountState(state);

      expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(isLoading);
      expect(wrapper.find('div').exists()).toBe(isLoading);
      expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    },
  );
});

describe('ViewStates — accessibility (SC-14 / WCAG 2.1 AA)', () => {
  it('has no axe violations in the error state', async () => {
    const wrapper = mountState('error');
    await expectNoAxeViolations(wrapper.element);
  });

  it('has no axe violations in the empty state', async () => {
    const wrapper = mountState('empty', {
      emptyKind: 'no-records',
      onCreate: () => {},
    });
    await expectNoAxeViolations(wrapper.element);
  });
});

describe('ViewStates — an axis without columns explains itself', () => {
  it('names the missing axis values instead of claiming an empty column', () => {
    const wrapper = mountState('empty', { emptyKind: 'no-axis-columns' });

    expect(wrapper.text()).toContain('Keine Spalten für diese Achse');
    expect(wrapper.text()).toContain('wählen Sie eine andere Achse');
    expect(wrapper.text()).not.toContain('Leere Spalte');
  });

  it('offers no create button for an unconfigured axis', () => {
    const wrapper = mount(ViewStates, {
      props: { state: 'empty', emptyKind: 'no-axis-columns' },
      attrs: { onCreate: () => {} },
    });

    expect(buttonContaining(wrapper, 'Ersten Datensatz anlegen')).toBeUndefined();
  });
});
