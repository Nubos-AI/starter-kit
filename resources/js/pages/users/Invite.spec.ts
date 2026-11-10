import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import MultiSelect from '@/components/ui/multi-select/MultiSelect.vue';
import Invite from '@/pages/users/Invite.vue';
import type { AssignableRole } from '@/types/users';

const { FormStub } = vi.hoisted(() => ({
    FormStub: {
        name: 'FormStub',
        props: ['transform', 'action', 'method', 'onSuccess'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: {} }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Form: FormStub,
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

const stubs = {};

const roles: AssignableRole[] = [
    {
        id: 'role-1',
        name: 'Vertrieb',
        scope: 'tenant',
        authority: null,
        is_system: false,
        grants_subteam_visibility: false,
        can_assign: true,
    },
    {
        id: 'role-2',
        name: 'Super-Admin',
        scope: 'platform',
        authority: 'super_admin',
        is_system: true,
        grants_subteam_visibility: false,
        can_assign: false,
    },
];

const teams = [
    { value: 'team-1', label: 'Vertrieb Nord' },
    { value: 'team-2', label: 'Support' },
];

type Wrapper = ReturnType<typeof mount>;

function mountInvite(): Wrapper {
    return mount(Invite, { props: { roles, teams }, global: { stubs } });
}

function transformOf(
    wrapper: Wrapper,
): (data: Record<string, unknown>) => Record<string, unknown> {
    return wrapper.findComponent(FormStub).props('transform') as (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
}

describe('users/Invite', () => {
    it('keeps the save button disabled until something was entered', async () => {
        const wrapper = mountInvite();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeDefined();

        await wrapper
            .get('#invite-emails')
            .setValue('anna@beispiel.de, ben@beispiel.de');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('marks a role the inviter may not assign as disabled', () => {
        const options = mountInvite()
            .findComponent(MultiSelect)
            .props('options') as Array<{ value: string; disabled?: boolean }>;

        expect(
            options.find((option) => option.value === 'role-2'),
        ).toMatchObject({ disabled: true, badge: 'Eskaliert' });
    });

    it('submits the selected roles and teams alongside the addresses', async () => {
        const wrapper = mountInvite();
        const selects = wrapper.findAllComponents(MultiSelect);

        selects[0].vm.$emit('update:modelValue', ['role-1']);
        selects[1].vm.$emit('update:modelValue', ['team-2']);
        await wrapper.vm.$nextTick();

        expect(transformOf(wrapper)({ emails: 'anna@beispiel.de' })).toEqual({
            emails: 'anna@beispiel.de',
            role_ids: ['role-1'],
            team_ids: ['team-2'],
        });
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountInvite();

        await wrapper.get('#invite-emails').setValue('anna@beispiel.de');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});
