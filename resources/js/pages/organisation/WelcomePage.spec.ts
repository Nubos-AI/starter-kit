import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WelcomePage from '@/pages/organisation/WelcomePage.vue';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const form = vi.hoisted(() => ({
    payload: null as Record<string, unknown> | null,
    url: '',
    verifyUrl: '',
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    useForm: () => ({
        processing: false,
        errors: form.errors,
        transform: (callback: () => Record<string, unknown>) => ({
            put: (url: string) => {
                form.payload = callback();
                form.url = url;
            },
        }),
        post: (url: string) => {
            form.verifyUrl = url;
        },
    }),
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => ({ url: '/nubos/engine/welcome-page', props: {} }),
}));

function render(
    canUpdate = true,
    domain: string | null = null,
    domainVerifiedAt: string | null = null,
    team: { name: string; address: string } | null = null,
) {
    return mount(WelcomePage, {
        props: {
            address: 'https://acme.nubos.test',
            team,
            cnameTarget: 'acme.nubos.test',
            domain,
            domainVerifiedAt,
            domainProof:
                domain === null
                    ? null
                    : {
                          recordName: `_nubos-site.${domain}`,
                          recordValue: 'nubos-site-verification=abc123',
                      },
            canUpdate,
        },
    });
}

describe('the welcome page settings', () => {
    beforeEach(() => {
        form.payload = null;
        form.verifyUrl = '';
        form.errors = {};
    });

    it('shows the address of the welcome page and the target the own domain points at', () => {
        const wrapper = render();

        expect(wrapper.get('[data-welcome-address]').text()).toBe(
            'https://acme.nubos.test',
        );
        expect(wrapper.get('[data-welcome-cname]').text()).toContain(
            'acme.nubos.test',
        );
    });

    it('shows the address of the active team beside the address of the organisation', () => {
        const wrapper = render(true, null, null, {
            name: 'Nubos Company',
            address: 'https://localhost/nubos/nubos-company',
        });

        expect(wrapper.get('[data-welcome-team-address]').text()).toBe(
            'https://localhost/nubos/nubos-company',
        );
        expect(wrapper.text()).toContain('Team „Nubos Company“');
    });

    it('shows no team address while no team is active', () => {
        expect(render().find('[data-welcome-team-address]').exists()).toBe(
            false,
        );
    });

    it('saves the own domain once it was changed', async () => {
        const wrapper = render();
        const save = () => wrapper.get('[data-form-save]');

        expect(save().attributes('disabled')).toBeDefined();

        await wrapper.get('#domain').setValue('chat.acme.de');

        expect(save().attributes('disabled')).toBeUndefined();

        await save().trigger('click');

        expect(form.payload).toEqual({ domain: 'chat.acme.de' });
        expect(form.url).toBe('/nubos/engine/welcome-page');
    });

    it('shows a rejected domain at the field', () => {
        form.errors = { domain: 'Diese Domain gehört zur Plattform.' };

        expect(render().text()).toContain('Diese Domain gehört zur Plattform.');
    });

    it('shows the domain read only to who may not change the organisation', () => {
        const wrapper = render(false, 'chat.acme.de');

        expect(wrapper.get('#domain').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-form-save]').exists()).toBe(false);
    });

    it('checks a saved own domain that is not verified yet', async () => {
        const wrapper = render(true, 'chat.acme.de');

        expect(
            wrapper
                .get('[data-welcome-verification]')
                .attributes('data-welcome-verification'),
        ).toBe('pending');

        await wrapper.get('[data-welcome-verify]').trigger('click');

        expect(form.verifyUrl).toBe('/nubos/engine/welcome-page/verify');
    });

    it('names the TXT record an apex domain needs while the own domain is not verified', () => {
        const wrapper = render(true, 'acme.de');

        expect(wrapper.get('[data-domain-proof-name]').text()).toBe(
            '_nubos-site.acme.de',
        );
        expect(wrapper.get('[data-domain-proof-value]').text()).toBe(
            'nubos-site-verification=abc123',
        );
        expect(wrapper.get('[data-domain-proof]').text()).toContain(
            'TXT-Eintrag',
        );
    });

    it('names no TXT record once the own domain is verified or before one was saved', () => {
        expect(
            render(true, 'acme.de', '2026-10-10T12:00:00+00:00')
                .find('[data-domain-proof]')
                .exists(),
        ).toBe(false);
        expect(render(true, null).find('[data-domain-proof]').exists()).toBe(
            false,
        );
    });

    it('shows when the own domain was verified', () => {
        const wrapper = render(
            true,
            'chat.acme.de',
            '2026-10-10T12:00:00+00:00',
        );

        expect(
            wrapper
                .get('[data-welcome-verification]')
                .attributes('data-welcome-verification'),
        ).toBe('verified');
    });

    it('offers no check before an own domain was saved or to who may not change the organisation', () => {
        expect(render(true, null).find('[data-welcome-verify]').exists()).toBe(
            false,
        );
        expect(
            render(false, 'chat.acme.de')
                .find('[data-welcome-verify]')
                .exists(),
        ).toBe(false);
    });
});
