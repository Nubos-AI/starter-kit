import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UserTeamRolesField from '@/components/users/UserTeamRolesField.vue';
import { comboboxStubs } from '@/tests/selectStubs';
import type { TeamRoleDraft } from '@/types/users';

const roleOptions = [{ value: 'role-lead', label: 'Teamleitung' }];
const teamOptions = [
    { value: 'team-1', label: 'Vertrieb Nord' },
    { value: 'team-2', label: 'Support' },
];

type Wrapper = ReturnType<typeof mount>;

function mountField(rows: TeamRoleDraft[], disabled = false): Wrapper {
    return mount(UserTeamRolesField, {
        props: { modelValue: rows, roleOptions, teamOptions, disabled },
        global: { stubs: { ...comboboxStubs } },
    });
}

function lastEmitted(wrapper: Wrapper): TeamRoleDraft[] {
    const events = wrapper.emitted('update:modelValue') ?? [];

    return events[events.length - 1]?.[0] as TeamRoleDraft[];
}

describe('users/UserTeamRolesField', () => {
    it('explains that the user holds no team role yet', () => {
        expect(mountField([]).find('[data-team-roles-empty]').exists()).toBe(
            true,
        );
    });

    it('adds an empty row to pick a role and a team', async () => {
        const wrapper = mountField([]);

        await wrapper.get('[data-team-role-add]').trigger('click');

        expect(lastEmitted(wrapper)).toEqual([
            { role_id: null, team_id: null },
        ]);
    });

    it('writes the chosen role and team into its row', async () => {
        const wrapper = mountField([{ role_id: null, team_id: null }]);

        await wrapper.get('select[data-team-role-role]').setValue('role-lead');

        expect(lastEmitted(wrapper)).toEqual([
            { role_id: 'role-lead', team_id: null },
        ]);

        await wrapper.setProps({ modelValue: lastEmitted(wrapper) });
        await wrapper.get('select[data-team-role-team]').setValue('team-2');

        expect(lastEmitted(wrapper)).toEqual([
            { role_id: 'role-lead', team_id: 'team-2' },
        ]);
    });

    it('removes a row again', async () => {
        const wrapper = mountField([
            { role_id: 'role-lead', team_id: 'team-1' },
            { role_id: 'role-lead', team_id: 'team-2' },
        ]);

        await wrapper
            .findAll('[data-testid="team-role-remove"]')[0]
            .trigger('click');

        expect(lastEmitted(wrapper)).toEqual([
            { role_id: 'role-lead', team_id: 'team-2' },
        ]);
    });

    it('locks every control on the own account', () => {
        const wrapper = mountField(
            [{ role_id: 'role-lead', team_id: 'team-1' }],
            true,
        );

        expect(
            wrapper.get('select[data-team-role-role]').attributes('disabled'),
        ).toBeDefined();
        expect(
            wrapper.get('[data-team-role-add]').attributes('disabled'),
        ).toBeDefined();
    });
});
