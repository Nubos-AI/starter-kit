import { render, screen } from '@testing-library/vue';
import { describe, expect, it, vi } from 'vitest';
import PageShell from '@/shared/ui/PageShell.vue';

vi.mock('@inertiajs/vue3', async () => ({
  Head: (await import('@/shared/testing/inertia')).headStub,
}));

const global = { stubs: { UiExtensionPoint: true } };

describe('PageShell', () => {
  it('uses the title as document title unless the page names its own', () => {
    const plain = render(PageShell, { props: { title: 'Ziele' }, global });

    expect(plain.container.querySelector('head-stub')?.getAttribute('title')).toBe('Ziele');

    plain.unmount();

    const { container } = render(PageShell, {
      props: { title: 'Pipeline bearbeiten', headTitle: 'Firmen — Pipeline bearbeiten' },
      global,
    });

    expect(screen.getByRole('heading', { name: 'Pipeline bearbeiten' })).toBeTruthy();
    expect(container.querySelector('head-stub')?.getAttribute('title')).toBe('Firmen — Pipeline bearbeiten');
  });

  it('stretches to the available height only when the page fills it', async () => {
    const { container, rerender } = render(PageShell, { props: { title: 'Ziele' }, global });

    expect(container.querySelector('[data-page-shell]')?.hasAttribute('data-page-shell-fill')).toBe(false);

    await rerender({ title: 'Ziele', fill: true });

    expect(container.querySelector('[data-page-shell]')?.hasAttribute('data-page-shell-fill')).toBe(true);
  });
});
