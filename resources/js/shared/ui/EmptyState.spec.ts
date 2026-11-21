import { Inbox } from '@lucide/vue';
import { render, screen } from '@testing-library/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import EmptyState from '@/shared/ui/EmptyState.vue';

describe('EmptyState', () => {
  it('renders the heading at the requested level', () => {
    const wrapper = mount(EmptyState, {
      props: {
        icon: Inbox,
        title: 'Nichts da',
        description: 'x',
        headingLevel: 'h3',
      },
    });

    expect(wrapper.find('h3').exists()).toBe(true);
    expect(wrapper.find('h2').exists()).toBe(false);
  });

  it('renders the inline variant as a single sentence without heading', () => {
    const { container } = render(EmptyState, {
      props: { variant: 'inline', description: 'Noch keine Einträge.' },
    });

    const sentence = container.querySelector('[data-empty-state="inline"]');

    expect(sentence?.tagName).toBe('P');
    expect(sentence?.textContent?.trim()).toBe('Noch keine Einträge.');
    expect(screen.queryByRole('heading')).toBeNull();
    expect(container.querySelector('svg')).toBeNull();
  });

  it('passes data hooks of the inline variant through to the sentence', () => {
    const { container } = render(EmptyState, {
      props: { variant: 'inline', description: 'Leer.' },
      attrs: { 'data-rows-empty': '' },
    });

    expect(container.querySelector('[data-empty-state="inline"]')?.hasAttribute('data-rows-empty')).toBe(true);
  });

  it('renders a follow-up action of the inline variant after the sentence', () => {
    const { container } = render(EmptyState, {
      props: { variant: 'inline', description: 'Noch keine Ziele.' },
      slots: { default: '<a href="/ziele" data-manage>Ziele verwalten</a>' },
    });

    const sentence = container.querySelector('[data-empty-state="inline"]');

    expect(sentence?.querySelector('[data-manage]')).not.toBeNull();
    expect(sentence?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Noch keine Ziele. Ziele verwalten');
  });

  it('keeps icon and heading in the default variant', () => {
    const { container } = render(EmptyState, {
      props: { icon: Inbox, title: 'Nichts da', description: 'Legen Sie etwas an.' },
    });

    expect(screen.getByRole('heading', { level: 2 }).textContent?.trim()).toBe('Nichts da');
    expect(container.querySelector('svg')).not.toBeNull();
    expect(container.querySelector('[data-empty-state="inline"]')).toBeNull();
  });
});
