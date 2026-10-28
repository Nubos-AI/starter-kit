import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Fields from '@/pages/objectTypes/edit/Fields.vue';
import type { FieldTypeOption } from '@/types/fields';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        url: '/engine/object-types/departments/edit/fields',
        props: {
            auth: {
                user: null,
                can: { 'object-types.update': true } as Record<string, boolean>,
                authority: null,
            },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    router: {
        on: vi.fn(() => vi.fn()),
        visit: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
    usePage: () => pageState,
}));

const FieldGridStub = {
    name: 'FieldGridStub',
    props: ['objectTypeSlug', 'fields', 'fieldTypes', 'groups', 'editable'],
    template: '<div class="field-grid-stub" />',
};

const fieldGroups = [
    {
        id: 'fg-address',
        key: 'address',
        label: 'Adresse',
        description: null,
        position: 1,
    },
];

const GroupsCardStub = {
    name: 'GroupsCardStub',
    props: ['objectTypeSlug', 'groups', 'editable'],
    template: '<div class="groups-card-stub" />',
};

const stubs = {
    ObjectTypeFieldGrid: FieldGridStub,
    ObjectTypeFieldDrawer: { template: '<div class="field-drawer-stub" />' },
    ObjectTypeFieldGroupsCard: GroupsCardStub,
};

const fieldTypes: FieldTypeOption[] = [
    {
        value: 'text_short',
        label: 'Kurzer Text',
        description: 'Eine Zeile Text.',
        category: 'text',
        categoryLabel: 'Text',
        categoryPosition: 1,
    } as FieldTypeOption,
];

const fields = [
    {
        id: 'fd-1',
        field_group_id: null,
        key: 'title',
        field_type: 'text_short',
        label: 'Titel',
        is_required: false,
    },
];

function objectType(
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id: 'ot-1',
        key: 'departments',
        slug: 'departments',
        name: 'Departments',
        business_key_prefix: 'DP',
        record_number_format: '##########',
        business_key_locked: false,
        is_system: false,
        storage_strategy: 'generic',
        hierarchy_relationship_type_id: null,
        ...overrides,
    };
}

function mountFields(
    payload: Record<string, unknown> = objectType(),
    rows: Array<Record<string, unknown>> = fields,
) {
    return mount(Fields, {
        props: {
            objectType: payload as never,
            fields: rows as never,
            fieldGroups,
            fieldTypes,
            objectTypeOptions: [],
            rollupTargets: [],
        },
        global: { stubs },
    });
}

describe('objectTypes/edit/Fields', () => {
    beforeEach(() => {
        pageState.props.auth.can = { 'object-types.update': true };
    });

    it('hands the field rows and their types down to the grid', () => {
        const grid = mountFields().findComponent(FieldGridStub);

        expect(grid.props('objectTypeSlug')).toBe('departments');
        expect(grid.props('fields')).toEqual(fields);
        expect(grid.props('fieldTypes')).toEqual(fieldTypes);
        expect(grid.props('editable')).toBe(true);
    });

    it('offers the new-field button to a holder of object-types.update', () => {
        expect(mountFields().find('[data-create-button]').exists()).toBe(true);
    });

    it('withholds it without the update permission', () => {
        pageState.props.auth.can = {};

        const wrapper = mountFields();

        expect(wrapper.find('[data-create-button]').exists()).toBe(false);
        expect(wrapper.findComponent(FieldGridStub).props('editable')).toBe(
            false,
        );
    });

    it('withholds it on a system object type despite the permission', () => {
        const wrapper = mountFields(objectType({ is_system: true }));

        expect(wrapper.find('[data-create-button]').exists()).toBe(false);
        expect(wrapper.find('.field-drawer-stub').exists()).toBe(false);
    });

    it('hands the groups to the grid and to the group card alike', () => {
        const wrapper = mountFields();

        expect(wrapper.findComponent(FieldGridStub).props('groups')).toEqual(
            fieldGroups,
        );
        expect(wrapper.findComponent(GroupsCardStub).props('groups')).toEqual(
            fieldGroups,
        );
        expect(wrapper.findComponent(GroupsCardStub).props('editable')).toBe(
            true,
        );
    });

    it('locks the group card on a system object type', () => {
        const wrapper = mountFields(objectType({ is_system: true }));

        expect(wrapper.findComponent(GroupsCardStub).props('editable')).toBe(
            false,
        );
    });

    it('explains an empty field list', () => {
        expect(mountFields(objectType(), []).text()).toContain(
            'Noch keine Felder definiert.',
        );
    });
});
