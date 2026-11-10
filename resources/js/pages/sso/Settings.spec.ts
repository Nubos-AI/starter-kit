import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Settings from '@/pages/sso/Settings.vue';
import { comboboxStubs } from '@/tests/selectStubs';
import type {
    ScimSettings,
    SsoConnectionSettings,
    SsoLoginProof,
} from '@/types/sso';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const calls = vi.hoisted(() => ({
    put: null as { url: string; payload: Record<string, unknown> } | null,
    post: [] as { url: string; payload: Record<string, unknown> }[],
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    useForm: () => ({
        processing: false,
        errors: calls.errors,
        transform: (callback: () => Record<string, unknown>) => ({
            put: (url: string) => {
                calls.put = { url, payload: callback() };
            },
            post: (url: string) => {
                calls.post.push({ url, payload: callback() });
            },
        }),
        post: (url: string) => {
            calls.post.push({ url, payload: {} });
        },
    }),
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => ({ url: '/nubos/engine/sso', props: {} }),
}));

function connection(
    overrides: Partial<SsoConnectionSettings> = {},
): SsoConnectionSettings {
    return {
        isEnabled: false,
        isEnforced: false,
        isJitEnabled: false,
        issuerUrl: null,
        clientId: null,
        hasClientSecret: false,
        allowedDomains: [],
        verifiedDomains: [],
        domainProofs: [],
        defaultRoleId: null,
        ...overrides,
    };
}

function loginProof(overrides: Partial<SsoLoginProof> = {}): SsoLoginProof {
    return {
        status: 'missing',
        verifiedAt: null,
        canTest: true,
        testReason: null,
        canEnforce: true,
        enforceReason: null,
        ...overrides,
    };
}

function render(
    overrides: Partial<SsoConnectionSettings> = {},
    scim: Omit<ScimSettings, 'baseUrl'> = {
        hasToken: false,
        needsRotation: false,
        tokenCreatedAt: null,
        lastUsedAt: null,
    },
    scimToken: string | null = null,
    proof: Partial<SsoLoginProof> = {},
) {
    return mount(Settings, {
        props: {
            connection: connection(overrides),
            loginProof: loginProof(proof),
            roleOptions: [
                { value: 'role-member', label: 'Mitglied' },
                { value: 'role-sales', label: 'Vertrieb' },
            ],
            callbackUrl: 'https://nubos.test/auth/sso/callback',
            scim: { baseUrl: 'https://nubos.test/scim/v2', ...scim },
            scimToken,
        },
        global: { stubs: { ...comboboxStubs } },
    });
}

describe('the single sign-on settings', () => {
    beforeEach(() => {
        calls.put = null;
        calls.post = [];
        calls.errors = {};
    });

    it('saves the connection with one domain per line once it was changed', async () => {
        const wrapper = render();
        const save = () => wrapper.get('[data-form-save]');

        expect(save().attributes('disabled')).toBeDefined();

        await wrapper.get('#issuer_url').setValue('https://idp.acme.test');
        await wrapper.get('#client_id').setValue('client-acme');
        await wrapper.get('#client_secret').setValue('secret');
        await wrapper
            .get('#allowed_domains')
            .setValue('acme.de\n\n acme.com \n');

        expect(save().attributes('disabled')).toBeUndefined();

        await save().trigger('click');

        expect(calls.put?.url).toBe('/nubos/engine/sso');
        expect(calls.put?.payload).toEqual({
            is_enabled: false,
            is_enforced: false,
            is_jit_enabled: false,
            issuer_url: 'https://idp.acme.test',
            client_id: 'client-acme',
            client_secret: 'secret',
            allowed_domains: ['acme.de', 'acme.com'],
            default_role_id: null,
        });
    });

    it('never shows a stored client secret and says that one is set', () => {
        const wrapper = render({ hasClientSecret: true });

        expect(
            (wrapper.get('#client_secret').element as HTMLInputElement).value,
        ).toBe('');
        expect(wrapper.get('[data-sso-secret-hint]').text()).toContain(
            'gesetzt',
        );
    });

    it('offers the default role only while just in time provisioning is on', () => {
        expect(render().get('#default_role_id').attributes('disabled')).toBe(
            '',
        );
        expect(
            render({ isJitEnabled: true })
                .get('#default_role_id')
                .attributes('disabled'),
        ).toBeUndefined();
    });

    it('tests the connection with the issuer from the form', async () => {
        const wrapper = render({ issuerUrl: 'https://idp.acme.test' });

        await wrapper.get('[data-sso-test]').trigger('click');

        expect(calls.post).toEqual([
            {
                url: '/nubos/engine/sso/test',
                payload: { issuer_url: 'https://idp.acme.test' },
            },
        ]);
    });

    it('says that the discovery check proves neither the client credentials nor the login', () => {
        const wrapper = render();

        expect(wrapper.get('[data-sso-test]').text()).toBe('Discovery prüfen');
        expect(wrapper.get('[data-sso-test-hint]').text()).toContain(
            'nicht geprüft',
        );
    });

    it('shows whether and when a login test proved the saved connection', () => {
        const proven = render({}, undefined, null, {
            status: 'verified',
            verifiedAt: '2026-10-30T11:00:00+00:00',
        });

        expect(
            proven
                .get('[data-sso-login-proof-status]')
                .attributes('data-sso-login-proof-status'),
        ).toBe('verified');
        expect(proven.get('[data-sso-login-proof]').text()).toContain(
            'Geprüft',
        );
        expect(
            proven.get('[data-sso-login-proof-verified-at]').text(),
        ).toContain('Erfolgreich geprüft am');

        const outdated = render({}, undefined, null, {
            status: 'outdated',
            verifiedAt: '2026-10-30T11:00:00+00:00',
        });

        expect(outdated.get('[data-sso-login-proof]').text()).toContain(
            'Veraltet',
        );

        expect(render().get('[data-sso-login-proof]').text()).toContain(
            'Nicht geprüft',
        );
    });

    it('starts the login test for the saved connection', async () => {
        const wrapper = render();

        await wrapper.get('[data-sso-login-test]').trigger('click');

        expect(calls.post).toEqual([
            { url: '/nubos/engine/sso/login-test', payload: {} },
        ]);
    });

    it('keeps the login test disabled with the reason the server names', () => {
        const wrapper = render({}, undefined, null, {
            canTest: false,
            testReason: 'Speichern Sie zuerst eine aktive Verbindung.',
        });

        expect(
            wrapper.get('[data-sso-login-test]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.get('[data-sso-login-test-reason]').text()).toBe(
            'Speichern Sie zuerst eine aktive Verbindung.',
        );
    });

    it('keeps the login test disabled while the form holds unsaved changes', async () => {
        const wrapper = render();

        await wrapper.get('#client_id').setValue('client-other');

        expect(
            wrapper.get('[data-sso-login-test]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.get('[data-sso-login-test-reason]').text()).toContain(
            'Speichern',
        );
    });

    it('warns that changed client credentials drop the proven domains and the login proof', async () => {
        const wrapper = render(
            {
                isEnabled: true,
                issuerUrl: 'https://idp.acme.test',
                clientId: 'client-acme',
                hasClientSecret: true,
                allowedDomains: ['acme.de'],
                verifiedDomains: ['acme.de'],
            },
            undefined,
            null,
            { status: 'verified', verifiedAt: '2026-10-01T08:00:00Z' },
        );

        expect(
            wrapper.find('[data-sso-credentials-reset-warning]').exists(),
        ).toBe(false);

        await wrapper.get('#client_id').setValue('client-other');

        expect(
            wrapper.get('[data-sso-credentials-reset-warning]').text(),
        ).toContain('Domains');

        await wrapper.get('#client_id').setValue('client-acme');
        await wrapper.get('#client_secret').setValue('rotated-secret');

        expect(
            wrapper.find('[data-sso-credentials-reset-warning]').exists(),
        ).toBe(true);
    });

    it('stays silent about changed client credentials while nothing is proven yet', async () => {
        const wrapper = render({ isEnabled: true, issuerUrl: null });

        await wrapper.get('#issuer_url').setValue('https://idp.acme.test');

        expect(
            wrapper.find('[data-sso-credentials-reset-warning]').exists(),
        ).toBe(false);
    });

    it('disables the enforce switch with the reason while no login proof is current', () => {
        const blocked = render({}, undefined, null, {
            canEnforce: false,
            enforceReason:
                'Starten Sie zuerst eine erfolgreiche Testanmeldung.',
        });

        expect(
            blocked.get('#is_enforced').attributes('disabled'),
        ).toBeDefined();
        expect(blocked.get('[data-sso-enforce-reason]').text()).toBe(
            'Starten Sie zuerst eine erfolgreiche Testanmeldung.',
        );

        const allowed = render();

        expect(
            allowed.get('#is_enforced').attributes('disabled'),
        ).toBeUndefined();
        expect(allowed.find('[data-sso-enforce-reason]').exists()).toBe(false);
    });

    it('shows the refusal of an enforcement and of a login test beside their controls', () => {
        calls.errors = {
            is_enforced: 'Erst nach einer Testanmeldung.',
            login_test: 'Verbindung unvollständig.',
        };

        const wrapper = render();

        expect(wrapper.text()).toContain('Erst nach einer Testanmeldung.');
        expect(wrapper.get('[data-sso-login-proof]').text()).toContain(
            'Verbindung unvollständig.',
        );
    });

    it('shows a field error the server bound to the issuer', () => {
        calls.errors = { issuer_url: 'Nicht erreichbar.' };

        expect(render().text()).toContain('Nicht erreichbar.');
    });

    it('shows every saved domain with its verification state and the txt record to create', () => {
        const wrapper = render({
            allowedDomains: ['acme.de', 'acme.com'],
            verifiedDomains: ['acme.de'],
            domainProofs: [
                {
                    domain: 'acme.de',
                    recordName: '_nubos-sso.acme.de',
                    recordValue: 'nubos-sso-verification=aaa',
                },
                {
                    domain: 'acme.com',
                    recordName: '_nubos-sso.acme.com',
                    recordValue: 'nubos-sso-verification=bbb',
                },
            ],
        });

        const verified = wrapper.get('[data-sso-domain-proof="acme.de"]');
        const pending = wrapper.get('[data-sso-domain-proof="acme.com"]');

        expect(
            verified
                .get('[data-sso-domain-status]')
                .attributes('data-sso-domain-status'),
        ).toBe('verified');
        expect(verified.text()).toContain('Bestätigt');
        expect(verified.find('[data-sso-domain-verify]').exists()).toBe(false);
        expect(
            pending
                .get('[data-sso-domain-status]')
                .attributes('data-sso-domain-status'),
        ).toBe('unverified');
        expect(pending.text()).toContain('Nicht bestätigt');
        expect(pending.get('[data-sso-domain-record-name]').text()).toBe(
            '_nubos-sso.acme.com',
        );
        expect(pending.get('[data-sso-domain-record-value]').text()).toBe(
            'nubos-sso-verification=bbb',
        );
    });

    it('checks the txt record of a pending domain', async () => {
        const wrapper = render({
            allowedDomains: ['acme.com'],
            domainProofs: [
                {
                    domain: 'acme.com',
                    recordName: '_nubos-sso.acme.com',
                    recordValue: 'nubos-sso-verification=bbb',
                },
            ],
        });

        const button = wrapper.get(
            '[data-sso-domain-proof="acme.com"] [data-sso-domain-verify]',
        );

        expect(button.text()).toBe('Prüfen');

        await button.trigger('click');

        expect(calls.post).toEqual([
            {
                url: '/nubos/engine/sso/domains/verify',
                payload: { domain: 'acme.com' },
            },
        ]);
    });

    it('shows the refusal of a domain check beside the domains', () => {
        calls.errors = {
            allowed_domains: 'Der TXT-Eintrag wurde nicht gefunden.',
        };

        expect(render().text()).toContain(
            'Der TXT-Eintrag wurde nicht gefunden.',
        );
    });

    it('creates the first scim token without asking', async () => {
        const wrapper = render();

        await wrapper.get('[data-scim-rotate]').trigger('click');

        expect(calls.post[0]?.url).toBe('/nubos/engine/sso/scim-token');
    });

    it('asks before it replaces an existing scim token', async () => {
        const wrapper = render(
            {},
            {
                hasToken: true,
                needsRotation: false,
                tokenCreatedAt: '2026-10-01T08:00:00+00:00',
                lastUsedAt: null,
            },
        );

        await wrapper.get('[data-scim-rotate]').trigger('click');

        expect(calls.post).toEqual([]);
        expect(
            wrapper.findComponent({ name: 'ConfirmDialog' }).props('open'),
        ).toBe(true);
    });

    it('tells the admin to rotate a scim token that no entitled person stands behind', () => {
        const stale = render(
            {},
            {
                hasToken: true,
                needsRotation: true,
                tokenCreatedAt: '2026-10-01T08:00:00+00:00',
                lastUsedAt: '2026-10-02T08:00:00+00:00',
            },
        );

        expect(stale.find('[data-scim-rotation-required]').exists()).toBe(true);
        expect(render().find('[data-scim-rotation-required]').exists()).toBe(
            false,
        );
    });

    it('shows a fresh scim token once', () => {
        const wrapper = render({}, undefined, 'scim_plain');

        expect(
            wrapper
                .findComponent({ name: 'ApiTokenSecretDialog' })
                .props('secret'),
        ).toBe('scim_plain');
    });
});
