import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Form from '@/pages/roles/Form.vue';
import type { FieldPermissionGroup } from '@/types/fieldPermissions';
import type { RoleFormMode, RoleFormPermissions } from '@/types/roles';

const { FormStub } = vi.hoisted(() => ({
    FormStub: {
        name: 'FormStub',
        props: ['transform', 'action', 'method', 'onBefore', 'onSuccess'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
}));

const { pageState } = vi.hoisted(() => ({
    pageState: {
        props: {
            auth: {
                user: null,
                can: {} as Record<string, boolean>,
                authority: null as string | null,
            },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Form: FormStub,
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => pageState,
}));

const CheckboxStub = {
    name: 'CheckboxStub',
    props: ['modelValue', 'id'],
    emits: ['update:modelValue'],
    template:
        '<input type="checkbox" :id="id" :data-state="String(modelValue)" :checked="modelValue === true" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
};

const stubs = {
    Head: { template: '<head-stub><slot /></head-stub>' },
    Checkbox: CheckboxStub,
    Select: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template:
            '<select class="dropdown" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
    },
    SelectTrigger: { render: () => null },
    SelectValue: { render: () => null },
    SelectContent: { template: '<slot />' },
    SelectItem: {
        props: ['value'],
        template: '<option :value="value"><slot /></option>',
    },
};

const scopes = [{ value: 'tenant', label: 'Tenant' }];
const authorities = [{ value: 'scope_admin', label: 'Scope Admin' }];

function permissions(assigned: string[] = []): RoleFormPermissions {
    return {
        assigned,
        tabs: [
            {
                key: 'roles',
                label: 'Roles',
                type: 'direct',
                groups: [
                    {
                        key: 'roles',
                        label: 'Roles',
                        permissions: [
                            { id: 'r-view', name: 'roles.view' },
                            { id: 'r-create', name: 'roles.create' },
                        ],
                    },
                ],
            },
            {
                key: 'data-model',
                label: 'Datenmodell',
                type: 'dropdown',
                groups: [
                    {
                        key: 'object-types',
                        label: 'ObjectTypes',
                        permissions: [
                            { id: 'ot-view', name: 'object-types.view' },
                        ],
                    },
                    {
                        key: 'reminder-types',
                        label: 'ReminderTypes',
                        permissions: [
                            { id: 'rt-view', name: 'reminder-types.view' },
                            { id: 'rt-create', name: 'reminder-types.create' },
                        ],
                    },
                ],
            },
            {
                key: 'records',
                label: 'Records',
                type: 'dropdown',
                groups: [
                    {
                        key: 'companies',
                        label: 'Firmen',
                        permissions: [
                            { id: 'c-view', name: 'companies.view' },
                            {
                                id: 'c-wm',
                                name: 'companies.watchers.manage',
                            },
                        ],
                    },
                ],
            },
        ],
    };
}

type Wrapper = ReturnType<typeof mount>;

function mountForm(
    mode: RoleFormMode,
    perms: RoleFormPermissions,
    role: unknown = null,
    actorAuthority: string | null = null,
    extra: Record<string, unknown> = {},
): Wrapper {
    pageState.props.auth.authority = actorAuthority;

    return mount(Form, {
        props: {
            mode,
            role: role as never,
            scopes,
            authorities,
            permissions: perms,
            fieldPermissions: [],
            isEscalated: false,
            ...extra,
        },
        global: { stubs },
    });
}

function transformOf(
    wrapper: Wrapper,
): (data: Record<string, unknown>) => Record<string, unknown> {
    return wrapper.findComponent(FormStub).props('transform') as (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
}

async function clickTab(wrapper: Wrapper, label: string): Promise<void> {
    const button = wrapper
        .findAll('button')
        .find((candidate) => candidate.text() === label);

    await button?.trigger('click');
}

function dropdownWith(wrapper: Wrapper, optionValue: string) {
    return wrapper
        .findAll('select.dropdown')
        .find((select) =>
            select.find(`option[value="${optionValue}"]`).exists(),
        );
}

describe('roles/Form — permission tabs', () => {
    it('renders a tab trigger for every backend tab', () => {
        const wrapper = mountForm('create', permissions());
        const triggers = wrapper.findAll('button').map((b) => b.text());

        expect(triggers).toEqual(
            expect.arrayContaining(['Roles', 'Datenmodell', 'Records']),
        );
    });

    it('renders readable action labels and pre-checks assigned permissions', () => {
        const wrapper = mountForm('create', permissions(['r-view']));

        expect(wrapper.text()).toContain('Ansehen');
        expect(wrapper.text()).toContain('Anlegen');
        expect(
            (wrapper.find('#permission-r-view').element as HTMLInputElement)
                .checked,
        ).toBe(true);
        expect(
            (wrapper.find('#permission-r-create').element as HTMLInputElement)
                .checked,
        ).toBe(false);
    });

    it('shows an indeterminate master checkbox for a partially selected group', () => {
        const wrapper = mountForm('create', permissions(['r-view']));

        expect(wrapper.find('#perm-all-roles').attributes('data-state')).toBe(
            'indeterminate',
        );
    });

    it('selects the whole group when the master checkbox is toggled', async () => {
        const wrapper = mountForm('create', permissions(['r-view']));

        await wrapper.find('#perm-all-roles').setValue(true);

        expect(wrapper.find('#perm-all-roles').attributes('data-state')).toBe(
            'true',
        );
        expect(transformOf(wrapper)({}).permission_ids).toEqual(
            expect.arrayContaining(['r-view', 'r-create']),
        );
    });

    it('shows the first dropdown option by default and lists all options', async () => {
        const wrapper = mountForm('create', permissions());
        await clickTab(wrapper, 'Datenmodell');
        const dataModel = dropdownWith(wrapper, 'reminder-types');

        expect(dataModel).toBeDefined();
        expect(dataModel?.text()).toContain('ObjectTypes');
        expect(dataModel?.text()).toContain('ReminderTypes');
        expect(wrapper.find('#permission-ot-view').exists()).toBe(true);
        expect(wrapper.find('#permission-rt-view').exists()).toBe(false);
    });

    it('switches the shown permissions when the dropdown changes', async () => {
        const wrapper = mountForm('create', permissions());
        await clickTab(wrapper, 'Datenmodell');
        const dataModel = dropdownWith(wrapper, 'reminder-types');

        await dataModel?.setValue('reminder-types');

        expect(wrapper.find('#permission-rt-view').exists()).toBe(true);
        expect(wrapper.find('#permission-rt-create').exists()).toBe(true);
        expect(wrapper.find('#permission-ot-view').exists()).toBe(false);
    });

    it('humanizes dotted object-type abilities in the records tab', async () => {
        const wrapper = mountForm('create', permissions());
        await clickTab(wrapper, 'Records');

        expect(wrapper.find('#permission-c-wm').exists()).toBe(true);
        expect(wrapper.text()).toContain('Beobachter verwalten');
    });

    it('submits the selected permission ids and the system flag', () => {
        const wrapper = mountForm(
            'edit',
            permissions(['r-view']),
            {
                id: 'role-1',
                name: 'admin',
                scope: 'tenant',
                authority: null,
                is_system: true,
            },
            'super_admin',
        );

        const payload = transformOf(wrapper)({ name: 'admin' });
        expect(payload.permission_ids).toEqual(['r-view']);
        expect(payload.is_system).toBe(true);
        expect(wrapper.find('#role-is-system').exists()).toBe(true);
    });
});

const editedRole = {
    id: 'role-1',
    name: 'admin',
    scope: 'tenant',
    authority: null,
    is_system: false,
    grants_subteam_visibility: false,
};

function fieldPermissions(
    overrides: Partial<FieldPermissionGroup['fields'][number]> = {},
): FieldPermissionGroup[] {
    return [
        {
            objectType: {
                id: 'obj-1',
                key: 'contact',
                slug: 'contact',
                name: 'Kontakt',
            },
            fields: [
                {
                    id: 'field-1',
                    key: 'email',
                    read: true,
                    write: true,
                    ...overrides,
                },
            ],
        },
    ];
}

function mountWithFieldPermissions(
    groups: FieldPermissionGroup[],
    isEscalated = false,
): Wrapper {
    return mountForm('edit', permissions(), editedRole, null, {
        fieldPermissions: groups,
        isEscalated,
    });
}

describe('roles/Form — field permission tab', () => {
    it('offers the field permission tab only when editing an existing role', async () => {
        const create = mountForm('create', permissions(), null, null, {
            fieldPermissions: fieldPermissions(),
        });

        expect(
            create.findAll('button').map((button) => button.text()),
        ).not.toContain('Feldrechte');

        const edit = mountWithFieldPermissions(fieldPermissions());

        expect(edit.findAll('button').map((button) => button.text())).toContain(
            'Feldrechte',
        );

        await clickTab(edit, 'Feldrechte');

        expect(edit.find('#field-read-field-1').exists()).toBe(true);
    });

    it('hides the tab when there is no object type at all', () => {
        const wrapper = mountWithFieldPermissions([]);

        expect(
            wrapper.findAll('button').map((button) => button.text()),
        ).not.toContain('Feldrechte');
    });

    it('sends no field permissions while nothing was changed', () => {
        const wrapper = mountWithFieldPermissions(
            fieldPermissions({ read: true, write: false }),
        );

        expect(transformOf(wrapper)({}).field_permissions).toBeUndefined();
    });

    it('sends only the changed field as a snake_case row', async () => {
        const wrapper = mountWithFieldPermissions(fieldPermissions());
        await clickTab(wrapper, 'Feldrechte');

        await wrapper.find('#field-read-field-1').setValue(false);

        expect(transformOf(wrapper)({}).field_permissions).toEqual([
            {
                field_definition_id: 'field-1',
                can_read: false,
                can_write: false,
            },
        ]);
    });

    it('sends nothing for an escalated role even after a toggle', async () => {
        const wrapper = mountWithFieldPermissions(fieldPermissions(), true);
        await clickTab(wrapper, 'Feldrechte');

        expect(transformOf(wrapper)({}).field_permissions).toBeUndefined();
    });
});

describe('roles/Form — saving field restrictions', () => {
    it('saves a restriction without a confirmation step', async () => {
        const wrapper = mountWithFieldPermissions(fieldPermissions());
        await clickTab(wrapper, 'Feldrechte');
        await wrapper.find('#field-read-field-1').setValue(false);

        expect(
            wrapper.getComponent(FormStub).props('onBefore'),
        ).toBeUndefined();
        expect(wrapper.text()).not.toContain('dauerhaft sperren');
    });
});

describe('roles/Form — server state after a save', () => {
    it('drops a stale draft when the server reports a different state', async () => {
        const wrapper = mountWithFieldPermissions(fieldPermissions());
        await clickTab(wrapper, 'Feldrechte');
        await wrapper.find('#field-read-field-1').setValue(false);

        expect(transformOf(wrapper)({}).field_permissions).toHaveLength(1);

        await wrapper.setProps({
            fieldPermissions: fieldPermissions({ read: false, write: false }),
        });

        expect(transformOf(wrapper)({}).field_permissions).toBeUndefined();
        expect(
            (wrapper.find('#field-read-field-1').element as HTMLInputElement)
                .checked,
        ).toBe(false);
        expect(wrapper.get('[data-testid="field-state-field-1"]').text()).toBe(
            'Eingeschränkt',
        );
    });
});

describe('roles/Form — subteam visibility flag', () => {
    it('submits the flag alongside the permission ids and disables the checkbox without the capability', () => {
        const wrapper = mountForm('create', permissions(['r-view']));
        const checkbox = wrapper.find('#role-grants-subteam-visibility');

        expect(checkbox.exists()).toBe(true);
        expect(checkbox.attributes('disabled')).toBeDefined();

        const payload = transformOf(wrapper)({ name: 'reader' });

        expect(payload.grants_subteam_visibility).toBe(false);
        expect(payload.permission_ids).toEqual(['r-view']);
    });

    it('sends the flag as true once an escalated manager checks the box', async () => {
        const wrapper = mountForm('create', permissions(), null, 'scope_admin');
        const checkbox = wrapper.find('#role-grants-subteam-visibility');

        expect(checkbox.attributes('disabled')).toBeUndefined();

        await checkbox.setValue(true);

        expect(
            transformOf(wrapper)({ name: 'reader' }).grants_subteam_visibility,
        ).toBe(true);
    });

    it('still sends the stored value of an existing role while the checkbox is disabled', () => {
        const wrapper = mountForm('edit', permissions(), {
            ...editedRole,
            grants_subteam_visibility: true,
        });

        expect(
            wrapper
                .find('#role-grants-subteam-visibility')
                .attributes('disabled'),
        ).toBeDefined();
        expect(
            transformOf(wrapper)({ name: 'admin' }).grants_subteam_visibility,
        ).toBe(true);
    });
});

describe('roles/Form — unsaved changes', () => {
    it('keeps saving disabled until a field changes', async () => {
        const wrapper = mountForm('edit', permissions(), editedRole);

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#role-name').setValue('administrator');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('treats a toggled permission as an unsaved change', async () => {
        const wrapper = mountForm('edit', permissions(), editedRole);

        await wrapper.find('#permission-r-view').setValue(true);

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountForm('edit', permissions(), editedRole);

        await wrapper.get('#role-name').setValue('administrator');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});
