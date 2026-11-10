import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import SsoLoginForm from '@/components/auth/SsoLoginForm.vue';

const { FormStub, formErrors } = vi.hoisted(() => ({
    FormStub: {
        name: 'FormStub',
        props: ['action', 'method'],
        template:
            '<form :data-action="action" :data-method="method"><slot :errors="errors()" :processing="false" /></form>',
        methods: {
            errors(): Record<string, string> {
                return formErrors.value;
            },
        },
    },
    formErrors: { value: {} as Record<string, string> },
}));

vi.mock('@inertiajs/vue3', () => ({
    Form: FormStub,
}));

function mountForm(error: string | null = null) {
    return mount(SsoLoginForm, { props: { error } });
}

describe('the single sign-on entry of the login page', () => {
    it('stays a single button until the user chooses single sign-on', async () => {
        const wrapper = mountForm();

        expect(wrapper.find('#sso-email').exists()).toBe(false);

        await wrapper.get('[data-sso-start]').trigger('click');

        expect(wrapper.get('#sso-email').attributes('type')).toBe('email');
        expect(wrapper.get('#sso-email').attributes('name')).toBe('email');
        expect(wrapper.get('form').attributes('data-action')).toBe('/auth/sso');
        expect(wrapper.get('form').attributes('data-method')).toBe('post');
        expect(wrapper.get('[data-sso-submit]').text()).toBe(
            'Weiter zum Identitätsanbieter',
        );
    });

    it('shows a refused address at the email field', async () => {
        formErrors.value = {
            email: 'Für diese E-Mail-Adresse ist keine Anmeldung per Single Sign-on eingerichtet.',
        };
        const wrapper = mountForm();

        await wrapper.get('[data-sso-start]').trigger('click');

        expect(wrapper.text()).toContain(
            'Für diese E-Mail-Adresse ist keine Anmeldung per Single Sign-on eingerichtet.',
        );
        formErrors.value = {};
    });

    it('opens with the reason when the identity provider sent the user back refused', () => {
        const wrapper = mountForm('Ihr Konto ist nicht aktiv.');

        expect(wrapper.get('[data-sso-error]').text()).toContain(
            'Ihr Konto ist nicht aktiv.',
        );
        expect(wrapper.find('#sso-email').exists()).toBe(true);
    });
});
