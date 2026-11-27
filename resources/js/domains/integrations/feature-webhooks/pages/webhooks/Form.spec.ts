import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { comboboxStubs } from '@/shared/testing/selectStubs';
import WebhookForm from './Form.vue';

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  usePage: () => ({ url: '/', props: {} }),
  Form: {
    name: 'InertiaFormStub',
    props: ['transform', 'onSuccess'],
    template: '<form><slot :errors="{}" :processing="false" /></form>',
  },
  router: {
    on: vi.fn(() => vi.fn()),
    visit: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  },
}));

const EVENT_TYPES = [
  { value: 'record.created', label: 'Record Created' },
  { value: 'record.updated', label: 'Record Updated' },
];

const OBJECT_TYPES = [{ value: 'obj-1', label: 'Firmen' }];

const ROLES = [{ value: 'member', label: 'member' }];

const TEAMS = [{ value: 'team-sales', label: 'Vertrieb Nord' }];

function mountForm(mode: 'create' | 'edit', subscription: Record<string, unknown> = {}) {
  return mount(WebhookForm, {
    global: { stubs: comboboxStubs },
    props: {
      mode,
      subscription:
        mode === 'create'
          ? null
          : ({
              id: 'hook-1',
              name: 'Kundenportal',
              targetUrl: 'https://hooks.example.test/webhook',
              authUsername: null,
              status: 'active',
              eventTypes: ['record.created'],
              objectTypeId: null,
              teamId: null,
              roleName: 'member',
              consecutiveFailures: 0,
              lastError: null,
              activatedAt: null,
              rotatedAt: null,
              ...subscription,
            } as never),
      secret: null,
      eventTypeOptions: EVENT_TYPES as never,
      objectTypeOptions: OBJECT_TYPES as never,
      roleOptions: ROLES as never,
      teamOptions: TEAMS as never,
    },
  });
}

describe('webhooks/Form', () => {
  it('asks for the password again once the destination moves to another server', async () => {
    const wrapper = mountForm('edit', { authUsername: 'hook-user' });

    expect(wrapper.find('[data-webhook-password-required]').exists()).toBe(false);

    await wrapper.get('#webhook-target-url').setValue('https://hooks.example.test/other-path');

    expect(wrapper.find('[data-webhook-password-required]').exists()).toBe(false);

    await wrapper.get('#webhook-target-url').setValue('https://attacker.example.org/webhook');

    expect(wrapper.find('[data-webhook-password-required]').exists()).toBe(true);

    await wrapper.get('#webhook-auth-password').setValue('fresh-password');

    expect(wrapper.find('[data-webhook-password-required]').exists()).toBe(false);
  });
});
