import { mount } from '@vue/test-utils';
import type { ColDef, ValueFormatterParams } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { Component } from 'vue';
import ApiTokensController from '@/actions/App/Http/Controllers/Settings/ApiTokensController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { Checkbox } from '@/components/ui/checkbox';
import ApiTokens from '@/pages/settings/ApiTokens.vue';
import { multiSelectStubs } from '@/tests/selectStubs';
import type { RowAction } from '@/types/rowAction';

const { postMock, deleteMock } = vi.hoisted(() => ({
    postMock: vi.fn(),
    deleteMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    router: {
        post: postMock,
        delete: deleteMock,
        on: vi.fn(() => vi.fn()),
        visit: vi.fn(),
    },
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

function columns(
    wrapper: ReturnType<typeof mountPage>,
): ColDef<PersonalApiTokenRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<PersonalApiTokenRow>[];
        }
    ).props('columnDefs');
}

function action(
    wrapper: ReturnType<typeof mountPage>,
    testId: string,
): RowAction<PersonalApiTokenRow> {
    const actions = columns(wrapper).find(
        (column) => column.colId === 'actions',
    )?.cellRendererParams?.actions as RowAction<PersonalApiTokenRow>[];

    const found = actions.find((entry) => entry.testId === testId);

    if (found === undefined) {
        throw new Error(`Action "${testId}" not found`);
    }

    return found;
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
        const abilities = columns(wrapper).find(
            (column) => column.colId === 'abilities',
        );
        const format = abilities?.valueFormatter as (
            params: ValueFormatterParams<PersonalApiTokenRow>,
        ) => string;

        expect(
            format({
                data: token(),
            } as ValueFormatterParams<PersonalApiTokenRow>),
        ).toBe('Datensätze lesen');
    });

    it('keeps saving disabled until the form has changed', async () => {
        const wrapper = mountPage();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeDefined();

        await wrapper.get('[data-testid="token-name"]').setValue('CLI');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('posts the new token with its abilities', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="token-name"]').setValue('CLI');
        await wrapper.get('.ui-multi-select').setValue(['records:read']);
        await wrapper.get('[data-form-save]').trigger('click');

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
        await wrapper.get('[data-form-save]').trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            '/settings/api-tokens',
            expect.objectContaining({ expiresAt: '2027-01-01T10:00' }),
            expect.anything(),
        );
    });

    it('asks before rotating and posts the rotation afterwards', async () => {
        const wrapper = mountPage([token()]);

        action(wrapper, 'token-rotate').onClick?.(token());
        await nextTick();

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

        action(wrapper, 'token-revoke').onClick?.(token());
        await nextTick();

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

describe('settings/ApiTokens — bulk revoke', () => {
    it('sends the chosen tokens to the bulk revoke endpoint', async () => {
        const wrapper = mountPage([token()]);

        mount(columns(wrapper)[0].cellRenderer as Component, {
            props: { params: { data: token() } },
        })
            .findComponent(Checkbox)
            .vm.$emit('update:modelValue', true);
        await nextTick();
        wrapper.findComponent(SelectionBulkBar).vm.$emit('delete');

        expect(postMock).toHaveBeenCalledWith(
            ApiTokensController.bulkDestroy.url(),
            { ids: ['7'] },
            expect.anything(),
        );
    });
});
