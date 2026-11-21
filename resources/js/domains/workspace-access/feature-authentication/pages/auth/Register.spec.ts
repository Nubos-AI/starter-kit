import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { selectStubs } from '@/shared/testing/selectStubs';
import Register from './Register.vue';

const { FormStub, pageProps } = vi.hoisted(() => ({
  FormStub: {
    name: 'FormStub',
    props: ['action', 'method', 'resetOnSuccess'],
    template: '<form><slot :errors="{}" :processing="false" /></form>',
  },
  pageProps: { value: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', async () => ({
  Head: (await import('@/shared/testing/inertia')).headStub,
  Link: (await import('@/shared/testing/inertia')).linkStub,
  Form: FormStub,
  usePage: () => ({ props: pageProps.value }),
  router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

type Wrapper = ReturnType<typeof mount>;

function mountPage(): Wrapper {
  return mount(Register, {
    props: {
      passwordRules: 'minlength: 8;',
      salutations: [{ value: 'mr', label: 'Herr' }],
    },
    global: { stubs: selectStubs },
  });
}

describe('auth/Register', () => {
  beforeEach(() => {
    pageProps.value = {};
  });

  it.each([
    ['while no module decides otherwise', {}],
    ['when business customers register', { registration: { requiresCompanyName: true } }],
  ])('asks for the company name %s', (_case, props) => {
    pageProps.value = props;

    const wrapper = mountPage();

    expect(wrapper.find('#company_name').exists()).toBe(true);
    expect(wrapper.get('#company_name').attributes('autofocus')).toBeDefined();
    expect(wrapper.get('#first_name').attributes('autofocus')).toBeUndefined();
  });

  it('drops the company name and focuses the first name when private customers register', () => {
    pageProps.value = { registration: { requiresCompanyName: false } };

    const wrapper = mountPage();

    expect(wrapper.find('#company_name').exists()).toBe(false);
    expect(wrapper.find('[name="company_name"]').exists()).toBe(false);
    expect(wrapper.get('#first_name').attributes('autofocus')).toBeDefined();
  });
});
