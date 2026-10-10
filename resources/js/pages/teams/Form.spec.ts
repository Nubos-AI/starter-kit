import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import MultiSelect from '@/components/ui/multi-select/MultiSelect.vue';
import TeamForm from '@/pages/teams/Form.vue';
import { comboboxAt, comboboxStubs, selectStubs } from '@/tests/selectStubs';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: {} }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'onSuccess'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

const OWNERS = [
    { value: 'user-1', label: 'Alex' },
    { value: 'user-2', label: 'Robin' },
];

function mountForm(
    overrides: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(TeamForm, {
        props: {
            mode: 'edit',
            team: {
                id: 'team-1',
                name: 'Sales',
                slug: 'sales',
                ownerId: null,
                roleIds: [],
                deniedPermissionIds: [],
                memberIds: ['user-1'],
            },
            parentOptions: [],
            ownerOptions: OWNERS,
            memberOptions: OWNERS,
            roleOptions: [],
            permissionOptions: [],
            ...overrides,
        },
        global: { stubs: { ...selectStubs, ...comboboxStubs } },
    });
}

describe('teams/Form', () => {
    it('keeps saving disabled until a text field changes', async () => {
        const wrapper = mountForm();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#team-name').setValue('Sales North');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('keeps saving disabled until a select changes', async () => {
        const wrapper = mountForm();

        await comboboxAt(wrapper).setValue('user-1');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountForm();

        await wrapper.get('#team-slug').setValue('sales-north');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });

    it('preselects the current members', () => {
        expect(
            mountForm().findComponent(MultiSelect).props('modelValue'),
        ).toEqual(['user-1']);
    });

    it('submits the selected member ids', async () => {
        const wrapper = mountForm();

        wrapper
            .findComponent(MultiSelect)
            .vm.$emit('update:modelValue', ['user-2']);
        await nextTick();

        const transform = wrapper
            .findComponent({ name: 'InertiaFormStub' })
            .props('transform') as (
            data: Record<string, unknown>,
        ) => Record<string, unknown>;

        expect(transform({ name: 'Sales' })).toMatchObject({
            member_ids: ['user-2'],
        });
    });

    it('treats a changed member selection as an unsaved change', async () => {
        const wrapper = mountForm();

        wrapper
            .findComponent(MultiSelect)
            .vm.$emit('update:modelValue', ['user-1', 'user-2']);
        await nextTick();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('offers no member field while creating a team', () => {
        const wrapper = mountForm({ mode: 'create', team: null });

        expect(wrapper.findComponent(MultiSelect).exists()).toBe(false);
    });
});
