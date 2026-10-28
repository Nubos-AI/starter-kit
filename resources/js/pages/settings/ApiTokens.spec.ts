import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ApiTokens from '@/pages/settings/ApiTokens.vue';
import { multiSelectStubs } from '@/tests/selectStubs';

const { postMock, deleteMock } = vi.hoisted(() => ({
    postMock: vi.fn(),
    deleteMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    router: { post: postMock, delete: deleteMock },
}));

interface PersonalApiTokenRow {
    id: string;
    name: string;
    abilities: string[];
    lastUsedAt: string | null;
    expiresAt: string | null;
    createdAt: string | null;
}

const abilityOptions = [
    { value: 'records:read', label: 'Datensätze lesen' },
    { value: 'records:write', label: 'Datensätze schreiben' },
];

const stubs = { ...multiSelectStubs };

function token(
    overrides: Partial<PersonalApiTokenRow> = {},
): PersonalApiTokenRow {
    return {
        id: '7',
        name: 'Laptop CLI',
        abilities: ['records:read'],
        lastUsedAt: null,
        expiresAt: null,
        createdAt: null,
        ...overrides,
    };
}

function mountPage(
    tokens: PersonalApiTokenRow[] = [],
    secret: string | null = null,
) {
    return mount(ApiTokens, {
        props: { tokens, abilityOptions, secret },
        global: { stubs },
    });
}

beforeEach(() => {
    postMock.mockReset();
    deleteMock.mockReset();
});

describe('settings/ApiTokens', () => {
    it('shows an empty state while no token exists', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="token-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="token-list"]').exists()).toBe(false);
    });

    it('lists the own tokens with their ability labels', () => {
        const wrapper = mountPage([token()]);

        expect(wrapper.find('[data-testid="token-list"]').text()).toContain(
            'Laptop CLI',
        );
        expect(wrapper.find('[data-testid="token-list"]').text()).toContain(
            'Datensätze lesen',
        );
    });

    it('keeps the create button disabled until name and abilities are set', async () => {
        const wrapper = mountPage();
        const button = wrapper.get('[data-testid="token-create"]');

        expect(button.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="token-name"]').setValue('CLI');

        expect(
            wrapper.get('[data-testid="token-create"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('posts the new token with its abilities', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="token-name"]').setValue('CLI');
        await wrapper.get('.ui-multi-select').setValue(['records:read']);
        await wrapper.get('[data-testid="token-create"]').trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            '/settings/api-tokens',
            {
                name: 'CLI',
                abilities: ['records:read'],
                expiresAt: null,
            },
            expect.anything(),
        );
    });

    it('sends an expiry date when one is entered', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="token-name"]').setValue('CLI');
        await wrapper.get('.ui-multi-select').setValue(['records:read']);
        await wrapper
            .get('[data-testid="token-expires"]')
            .setValue('2027-01-01T10:00');
        await wrapper.get('[data-testid="token-create"]').trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            '/settings/api-tokens',
            expect.objectContaining({ expiresAt: '2027-01-01T10:00' }),
            expect.anything(),
        );
    });

    it('asks before rotating and posts the rotation afterwards', async () => {
        const wrapper = mountPage([token()]);

        await wrapper.get('[data-testid="rotate-7"]').trigger('click');

        expect(postMock).not.toHaveBeenCalled();

        wrapper
            .findAllComponents(ConfirmDialog)
            .find((entry) => entry.props('open') === true)!
            .vm.$emit('confirm');
        await nextTick();

        expect(postMock).toHaveBeenCalledWith(
            '/settings/api-tokens/7/rotate',
            {},
            expect.anything(),
        );
    });

    it('asks before revoking and deletes the token afterwards', async () => {
        const wrapper = mountPage([token()]);

        await wrapper.get('[data-testid="revoke-7"]').trigger('click');

        expect(deleteMock).not.toHaveBeenCalled();

        wrapper
            .findAllComponents(ConfirmDialog)
            .find((entry) => entry.props('open') === true)!
            .vm.$emit('confirm');
        await nextTick();

        expect(deleteMock).toHaveBeenCalledWith(
            '/settings/api-tokens/7',
            expect.anything(),
        );
    });

    it('reveals a freshly flashed secret once', async () => {
        mountPage([token()], 'plain-text-secret');
        await nextTick();

        expect(
            document.body.querySelector('[data-api-token-secret]')?.textContent,
        ).toContain('plain-text-secret');
    });
});
