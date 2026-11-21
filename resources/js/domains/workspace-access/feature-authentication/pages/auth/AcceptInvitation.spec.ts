import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { SalutationOption } from '@/domains/workspace-access/data/types/users';
import { selectStubs } from '@/shared/testing/selectStubs';
import AcceptInvitation from './AcceptInvitation.vue';

const { FormStub } = vi.hoisted(() => ({
  FormStub: {
    name: 'FormStub',
    props: ['action', 'method', 'resetOnSuccess'],
    template: '<form><slot :errors="{}" :processing="false" /></form>',
  },
}));

vi.mock('@inertiajs/vue3', async () => ({
  Head: (await import('@/shared/testing/inertia')).headStub,
  Form: FormStub,
  router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

const salutations: SalutationOption[] = [
  { value: 'mr', label: 'Mr.' },
  { value: 'mx', label: 'Mx.' },
];

type Wrapper = ReturnType<typeof mount>;

function mountPage(): Wrapper {
  return mount(AcceptInvitation, {
    props: {
      token: 'raw-token',
      email: 'neu@beispiel.de',
      salutations,
      passwordRules: 'minlength: 8;',
    },
    global: { stubs: selectStubs },
  });
}

describe('auth/AcceptInvitation', () => {
  it('posts to the invitation of the given token', () => {
    const action = mountPage().findComponent(FormStub).props('action');

    expect(action).toContain('/invitations/raw-token');
  });

  it('shows the invited address read only', () => {
    const email = mountPage().get<HTMLInputElement>('#email');

    expect(email.element.value).toBe('neu@beispiel.de');
    expect(email.attributes('readonly')).toBeDefined();
  });
});
