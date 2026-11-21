import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { expectNoAxeViolations } from '@/shared/testing/axe';
import { buttonContaining } from '@/shared/testing/dom';
import SearchStates from './SearchStates.vue';

describe('SearchStates — empty state', () => {
  it('renders the no-match copy without a call to action', () => {
    const wrapper = mount(SearchStates, {
      props: { state: 'empty', emptyKind: 'no-match' },
    });

    expect(wrapper.text()).toContain('Keine Treffer');
    expect(wrapper.findAll('button')).toHaveLength(0);
  });
});

describe('SearchStates — error state', () => {
  it('renders an alert with the message and emits retry once on click', async () => {
    const wrapper = mount(SearchStates, {
      props: {
        state: 'error',
        errorMessage: 'Zeitüberschreitung bei der Suche.',
      },
    });

    const alert = wrapper.find('[role="alert"]');
    expect(alert.exists()).toBe(true);
    expect(wrapper.text()).toContain('Suche fehlgeschlagen');
    expect(wrapper.text()).toContain('Zeitüberschreitung bei der Suche.');

    const retry = buttonContaining(wrapper, 'Erneut versuchen');
    expect(retry).toBeDefined();
    await retry!.trigger('click');
    expect(wrapper.emitted('retry')).toHaveLength(1);
  });
});

describe('SearchStates — loading and ready state', () => {
  it.each([
    ['loading', true],
    ['ready', false],
  ] as const)(
    'renders an aria-hidden skeleton only while loading and never an alert in the %s state',
    (state, isLoading) => {
      const wrapper = mount(SearchStates, { props: { state } });

      expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(isLoading);
      expect(wrapper.find('div').exists()).toBe(isLoading);
      expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    },
  );
});

describe('SearchStates — accessibility (WCAG 2.1 AA)', () => {
  it('has no axe violations in the error state', async () => {
    const wrapper = mount(SearchStates, {
      props: { state: 'error', errorMessage: 'Fehler.' },
    });
    await expectNoAxeViolations(wrapper.element);
  });

  it('has no axe violations in the empty state', async () => {
    const wrapper = mount(SearchStates, {
      props: { state: 'empty', emptyKind: 'no-match' },
    });
    await expectNoAxeViolations(wrapper.element);
  });
});
