import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import Details from '@/pages/teams/edit/Details.vue';
import { comboboxAt, comboboxStubs, selectStubs } from '@/tests/selectStubs';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: {} }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'onSuccess', 'options'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

const OWNERS = [
    { value: 'user-1', label: 'Alex' },
    { value: 'user-2', label: 'Robin' },
];

function mountDetails(
    overrides: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(Details, {
        props: {
            mode: 'edit',
            team: {
                id: 'team-1',
                name: 'Sales',
                slug: 'sales',
                ownerId: null,
            },
            parentOptions: [],
            ownerOptions: OWNERS,
            ...overrides,
        },
        global: { stubs: { ...selectStubs, ...comboboxStubs } },
    });
}

function transformOf(
    wrapper: ReturnType<typeof mount>,
): (data: Record<string, unknown>) => Record<string, unknown> {
    return wrapper
        .findComponent({ name: 'InertiaFormStub' })
        .props('transform') as (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
}

describe('teams/edit/Details', () => {
    it('keeps saving disabled until a text field changes', async () => {
        const wrapper = mountDetails();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#team-name').setValue('Sales North');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('keeps saving disabled until the owner changes', async () => {
        const wrapper = mountDetails();

        await comboboxAt(wrapper).setValue('user-1');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountDetails();

        await wrapper.get('#team-slug').setValue('sales-north');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });

    it('submits only the details, never members, roles or denied permissions', async () => {
        const wrapper = mountDetails();

        await comboboxAt(wrapper).setValue('user-2');

        const payload = transformOf(wrapper)({ name: 'Sales', slug: 'sales' });

        expect(payload).toEqual({
            name: 'Sales',
            slug: 'sales',
            owner_id: 'user-2',
        });
    });

    it('places the save row inside the details card', () => {
        expect(
            mountDetails()
                .find('[data-team-details] [data-form-save]')
                .exists(),
        ).toBe(true);
    });

    it('offers the parent team only while creating a team', () => {
        expect(mountDetails().find('#team-parent').exists()).toBe(false);
        expect(
            mountDetails({ mode: 'create', team: null })
                .find('#team-parent')
                .exists(),
        ).toBe(true);
    });
});
