import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ActivityTypeForm from './Form.vue';

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  usePage: () => ({ url: '/', props: {} }),
  Form: {
    name: 'InertiaFormStub',
    props: ['transform', 'onSuccess'],
    template: '<form><slot :errors="{}" :processing="false" /></form>',
  },
  router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

function mountForm() {
  return mount(ActivityTypeForm, {
    props: {
      mode: 'edit',
      activityType: { id: 'type-1', name: 'Call' },
    },
  });
}

describe('activityTypes/Form', () => {
  it('keeps saving disabled until something changes', async () => {
    const wrapper = mountForm();

    expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

    await wrapper.get('#activity-type-name').setValue('Follow-up');

    expect(wrapper.get('[data-form-save]').attributes('disabled')).toBeUndefined();
  });

  it('asks before leaving with pending changes', async () => {
    const wrapper = mountForm();

    await wrapper.get('#activity-type-name').setValue('Follow-up');
    await wrapper.get('[data-form-cancel]').trigger('click');
    await nextTick();

    expect(document.body.textContent).toContain('Änderungen verwerfen?');
  });
});
