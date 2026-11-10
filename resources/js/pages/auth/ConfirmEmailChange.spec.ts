import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ConfirmEmailChange from '@/pages/auth/ConfirmEmailChange.vue';

const { FormStub, formState } = vi.hoisted(() => {
    const formState = { errors: {} as Record<string, string> };

    return {
        formState,
        FormStub: {
            name: 'FormStub',
            props: ['action', 'method'],
            setup: () => ({ formState }),
            template:
                '<form><slot :errors="formState.errors" :processing="false" /></form>',
        },
    };
});

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Form: FormStub,
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

function mountPage() {
    return mount(ConfirmEmailChange, {
        props: { token: 'raw-token', email: 'ada.lovelace@example.com' },
    });
}

afterEach(() => {
    formState.errors = {};
});

describe('auth/ConfirmEmailChange', () => {
    it('posts the confirmation for the given token', () => {
        const form = mountPage().findComponent(FormStub);

        expect(form.props('action')).toContain('/email-change/raw-token');
        expect(form.props('method')).toBe('post');
    });

    it('shows the refusal of an address that can no longer be taken over beside the address', () => {
        formState.errors = { email: 'E-Mail-Adresse ist bereits vergeben.' };

        const wrapper = mountPage();

        expect(wrapper.get<HTMLInputElement>('#email').element.value).toBe(
            'ada.lovelace@example.com',
        );
        expect(wrapper.text()).toContain(
            'E-Mail-Adresse ist bereits vergeben.',
        );
    });
});
