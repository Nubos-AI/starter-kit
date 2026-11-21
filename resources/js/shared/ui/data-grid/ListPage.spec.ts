import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';

vi.mock('@inertiajs/vue3', async () => ({
  Head: (await import('@/shared/testing/inertia')).headStub,
}));

const stubs = { UiExtensionPoint: true };

describe('ListPage', () => {
  it('offers the list extension points before and after the list around the heading actions', () => {
    const wrapper = mount(ListPage, {
      props: { title: 'Fähigkeiten' },
      slots: { default: '<div data-list-body />' },
      global: { stubs },
    });

    expect(wrapper.findAllComponents(UiExtensionPoint).map((point) => point.props('name'))).toEqual([
      'lists.before',
      'heading.actions',
      'lists.after',
    ]);
  });
});
