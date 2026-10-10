import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import EffectivePermissions from '@/components/authorization/EffectivePermissions.vue';
import MultiSelect from '@/components/ui/multi-select/MultiSelect.vue';
import Form from '@/pages/users/Form.vue';
import type {
    AssignableRole,
    SalutationOption,
    UserFormPayload,
} from '@/types/users';

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
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn(), post: vi.fn() },
}));

const stubs = {};

const salutations: SalutationOption[] = [
    { value: 'mr', label: 'Mr.' },
    { value: 'ms', label: 'Ms.' },
];

const roles: AssignableRole[] = [
    {
        id: 'role-1',
        name: 'Vertrieb',
        scope: 'tenant',
        authority: null,
        is_system: false,
        grants_subteam_visibility: false,
        can_assign: true,
        permissions: [
            { id: 'p-1', name: 'members.view', group: 'members' },
            { id: 'p-2', name: 'roles.view', group: 'roles' },
        ],
    },
    {
        id: 'role-2',
        name: 'Support',
        scope: 'tenant',
        authority: null,
        is_system: false,
        grants_subteam_visibility: false,
        can_assign: true,
        permissions: [{ id: 'p-3', name: 'members.update', group: 'members' }],
    },
    {
        id: 'role-3',
        name: 'Super-Admin',
        scope: 'platform',
        authority: 'super_admin',
        is_system: true,
        grants_subteam_visibility: false,
        can_assign: false,
        permissions: [],
    },
];

function user(overrides: Partial<UserFormPayload> = {}): UserFormPayload {
    return {
        id: 'user-1',
        salutation: 'ms',
        first_name: 'Jo',
        last_name: 'Brandt',
        email: 'jo@example.test',
        role_ids: ['role-1'],
        denied_permission_ids: [],
        team_ids: ['team-1'],
        status: 'accepted',
        can_update_password: false,
        can_manage_access: true,
        ...overrides,
    };
}

const permissions = [
    { value: 'perm-1', label: 'records.view' },
    { value: 'perm-2', label: 'records.update' },
    { value: 'perm-3', label: 'members.view' },
];

const teams = [
    { value: 'team-1', label: 'Vertrieb Nord' },
    { value: 'team-2', label: 'Support' },
];

type Wrapper = ReturnType<typeof mount>;

function mountForm(payload: UserFormPayload = user()): Wrapper {
    return mount(Form, {
        props: { user: payload, salutations, roles, teams, permissions },
        global: { stubs },
    });
}

function teamSelect(wrapper: Wrapper) {
    return wrapper.findAllComponents(MultiSelect)[2];
}

async function setTeams(wrapper: Wrapper, ids: string[]): Promise<void> {
    teamSelect(wrapper).vm.$emit('update:modelValue', ids);
    await wrapper.vm.$nextTick();
}

function transformOf(
    wrapper: Wrapper,
): (data: Record<string, unknown>) => Record<string, unknown> {
    return wrapper.findComponent(FormStub).props('transform') as (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
}

async function setRoles(wrapper: Wrapper, ids: string[]): Promise<void> {
    wrapper.findComponent(MultiSelect).vm.$emit('update:modelValue', ids);
    await wrapper.vm.$nextTick();
}

describe('users/Form', () => {
    it('preselects the assigned roles', () => {
        const wrapper = mountForm();

        expect(wrapper.findComponent(MultiSelect).props('modelValue')).toEqual([
            'role-1',
        ]);
    });

    it('marks roles the user may not assign as disabled', () => {
        const wrapper = mountForm();
        const options = wrapper
            .findComponent(MultiSelect)
            .props('options') as Array<{
            value: string;
            disabled?: boolean;
            badge?: string;
        }>;

        expect(
            options.find((option) => option.value === 'role-3'),
        ).toMatchObject({ disabled: true, badge: 'Eskaliert' });
        expect(
            options.find((option) => option.value === 'role-1')?.disabled,
        ).toBe(false);
    });

    it('submits the selected role ids alongside the profile fields', async () => {
        const wrapper = mountForm();

        await setRoles(wrapper, ['role-1', 'role-2']);

        expect(transformOf(wrapper)({ first_name: 'Jo' })).toEqual({
            first_name: 'Jo',
            role_ids: ['role-1', 'role-2'],
            team_ids: ['team-1'],
            denied_permission_ids: [],
        });
    });

    it('preselects the teams the user belongs to', () => {
        expect(teamSelect(mountForm()).props('modelValue')).toEqual(['team-1']);
    });

    it('offers every team of the tenant as an option', () => {
        expect(teamSelect(mountForm()).props('options')).toEqual([
            { value: 'team-1', label: 'Vertrieb Nord' },
            { value: 'team-2', label: 'Support' },
        ]);
    });

    it('submits the selected team ids alongside the roles', async () => {
        const wrapper = mountForm();

        await setTeams(wrapper, ['team-2']);

        expect(transformOf(wrapper)({ first_name: 'Jo' })).toEqual({
            first_name: 'Jo',
            role_ids: ['role-1'],
            team_ids: ['team-2'],
            denied_permission_ids: [],
        });
    });

    it('treats a changed team selection as an unsaved change', async () => {
        const wrapper = mountForm();

        await setTeams(wrapper, ['team-1', 'team-2']);

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('unions the permissions of every selected role', async () => {
        const wrapper = mountForm();

        await setRoles(wrapper, ['role-1', 'role-2']);

        const preview = wrapper.findComponent(EffectivePermissions);

        expect(preview.text()).toContain('Ansehen');
        expect(preview.text()).toContain('Bearbeiten');
        expect(preview.findAll('[data-effective-group]')).toHaveLength(2);
    });

    it('warns that an escalated role overrides the individual permissions', async () => {
        const wrapper = mountForm();

        await setRoles(wrapper, ['role-3']);

        expect(wrapper.findComponent(EffectivePermissions).text()).toContain(
            'Eskalierte Rolle ausgewählt',
        );
    });

    it('states that a user without roles has no permissions', async () => {
        const wrapper = mountForm();

        await setRoles(wrapper, []);

        expect(wrapper.findComponent(EffectivePermissions).text()).toContain(
            'keine Rechte',
        );
    });

    it('keeps saving disabled until a field changes', async () => {
        const wrapper = mountForm();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#user-first-name').setValue('Joanna');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('treats a changed role selection as an unsaved change', async () => {
        const wrapper = mountForm();

        await setRoles(wrapper, ['role-1', 'role-2']);

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountForm();

        await wrapper.get('#user-first-name').setValue('Joanna');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});

describe('users/Form — password card', () => {
    it('hides the password card when the admin may not set the password', () => {
        expect(mountForm().find('[data-user-password-card]').exists()).toBe(
            false,
        );
    });

    it('shows the password card when the admin may set the password', () => {
        const wrapper = mountForm(user({ can_update_password: true }));

        expect(wrapper.find('[data-user-password-card]').exists()).toBe(true);
    });

    it('keeps the email read-only unless the admin may set the password', () => {
        expect(
            mountForm().find('#user-email').attributes('readonly'),
        ).toBeDefined();
        expect(
            mountForm(user({ can_update_password: true }))
                .find('#user-email')
                .attributes('readonly'),
        ).toBeUndefined();
    });

    it('locks roles, denials and teams on the own account', () => {
        const own = mountForm(user({ can_manage_access: false }))
            .findAllComponents(MultiSelect)
            .map((select) => select.props('disabled'));
        const foreign = mountForm()
            .findAllComponents(MultiSelect)
            .map((select) => select.props('disabled'));

        expect(own).toEqual([true, true, true]);
        expect(foreign).toEqual([false, false, false]);
    });

    it('falls back to the email as the heading of an invited user', () => {
        const wrapper = mountForm(
            user({ first_name: null, last_name: null, status: 'invited' }),
        );

        expect(wrapper.text()).toContain('jo@example.test');
    });
});

describe('users/Form — withdrawn permissions', () => {
    it('strikes through a permission the role grants but the user has withdrawn', async () => {
        const wrapper = mountForm(user({ denied_permission_ids: ['perm-1'] }));

        await setRoles(wrapper, ['role-1']);

        const preview = wrapper.findComponent(EffectivePermissions);
        const struck = preview
            .findAll('[data-effective-ability]')
            .filter((node) => node.classes().includes('line-through'));

        expect(struck).toHaveLength(0);
    });

    it('marks a withdrawn permission that one of the roles actually grants', async () => {
        const wrapper = mountForm(user({ denied_permission_ids: ['perm-3'] }));

        await setRoles(wrapper, ['role-1']);

        const struck = wrapper
            .findComponent(EffectivePermissions)
            .findAll('[data-effective-ability]')
            .filter((node) => node.classes().includes('line-through'));

        expect(struck).toHaveLength(1);
        expect(struck[0].text()).toContain('Ansehen');
    });
});
