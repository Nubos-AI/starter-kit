import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Welcome from '@/pages/Welcome.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        props: ['title'],
        template: '<head-stub :title="title"><slot /></head-stub>',
    },
    Link: {
        props: ['href'],
        template: '<a :href="href.url"><slot /></a>',
    },
}));

const render = (canRegister: boolean) =>
    mount(Welcome, { props: { canRegister, tenant: null } });

describe('the welcome page', () => {
    it('offers a guest the sign-in in german', () => {
        const signIn = render(true).get('[data-welcome-sign-in]');

        expect(signIn.text()).toBe('Anmelden');
        expect(signIn.attributes('href')).toBe('/login');
    });

    it('offers the registration while it is enabled', () => {
        const register = render(true).get('[data-welcome-register]');

        expect(register.text()).toBe('Registrieren');
        expect(register.attributes('href')).toBe('/register');
    });

    it('hides the registration while it is disabled', () => {
        expect(render(false).find('[data-welcome-register]').exists()).toBe(
            false,
        );
    });
});

describe('the welcome page of a tenant', () => {
    it('names the tenant whose address the visitor opened', () => {
        const wrapper = mount(Welcome, {
            props: { canRegister: false, tenant: { name: 'Acme' } },
        });

        expect(wrapper.get('[data-welcome-tenant]').text()).toBe('Acme');
    });

    it('names no tenant on the platform address', () => {
        expect(render(true).find('[data-welcome-tenant]').exists()).toBe(false);
    });
});
