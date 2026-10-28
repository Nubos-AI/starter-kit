import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Register from '@/pages/auth/Register.vue';
import { selectStubs } from '@/tests/selectStubs';

const { FormStub, pageProps } = vi.hoisted(() => ({
    FormStub: {
        name: 'FormStub',
        props: ['action', 'method', 'resetOnSuccess'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    pageProps: { value: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { template: '<a><slot /></a>' },
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

    it('asks for the company name while no module decides otherwise', () => {
        const wrapper = mountPage();

        expect(wrapper.find('#company_name').exists()).toBe(true);
        expect(
            wrapper.get('#company_name').attributes('autofocus'),
        ).toBeDefined();
        expect(
            wrapper.get('#first_name').attributes('autofocus'),
        ).toBeUndefined();
    });

    it('asks for the company name when business customers register', () => {
        pageProps.value = { registration: { requiresCompanyName: true } };

        expect(mountPage().find('#company_name').exists()).toBe(true);
    });

    it('drops the company name and focuses the first name when private customers register', () => {
        pageProps.value = { registration: { requiresCompanyName: false } };

        const wrapper = mountPage();

        expect(wrapper.find('#company_name').exists()).toBe(false);
        expect(wrapper.find('[name="company_name"]').exists()).toBe(false);
        expect(
            wrapper.get('#first_name').attributes('autofocus'),
        ).toBeDefined();
    });
});
