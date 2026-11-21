import { render } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import SkeletonList from '@/shared/ui/SkeletonList.vue';

describe('SkeletonList', () => {
  it('renders three medium placeholders by default', () => {
    const { container } = render(SkeletonList);

    const items = container.querySelectorAll('[data-skeleton-list] [data-slot="skeleton"]');

    expect(items).toHaveLength(3);
    expect(container.querySelector('[data-skeleton-list]')?.getAttribute('data-skeleton-size')).toBe('md');
  });

  it('renders the requested count at the requested size', () => {
    const { container } = render(SkeletonList, { props: { count: 5, size: 'lg' } });

    const items = container.querySelectorAll('[data-slot="skeleton"]');

    expect(items).toHaveLength(5);
    expect(container.querySelector('[data-skeleton-list]')?.getAttribute('data-skeleton-size')).toBe('lg');
  });

  it('hides the placeholders from assistive technology while announcing the busy state', () => {
    const { container } = render(SkeletonList);

    const list = container.querySelector('[data-skeleton-list]');

    expect(list?.getAttribute('aria-busy')).toBe('true');
    expect(list?.querySelector('[data-slot="skeleton"]')?.getAttribute('aria-hidden')).toBe('true');
  });
});
