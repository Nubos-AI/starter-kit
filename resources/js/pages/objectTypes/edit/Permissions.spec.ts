import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Permissions from '@/pages/objectTypes/edit/Permissions.vue';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        url: '/engine/object-types/departments/edit/permissions',
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
    Link: { template: '<a><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => pageState,
}));

const CheckboxStub = {
    name: 'CheckboxStub',
    props: ['modelValue', 'disabled'],
    template:
        '<input type="checkbox" :disabled="disabled" :checked="modelValue" />',
};

type Wrapper = ReturnType<typeof mount>;

const objectType = {
    id: 'ot-1',
    key: 'departments',
    slug: 'departments',
    name: 'Abteilungen',
    business_key_prefix: 'DP',
    record_number_format: '##########',
    business_key_locked: false,
    is_system: false,
    storage_strategy: 'generic',
    hierarchy_relationship_type_id: null,
};

function mountMatrix(matrix: Record<string, unknown>): Wrapper {
    return mount(Permissions, {
        props: { objectType: objectType as never, matrix: matrix as never },
        global: { stubs: { Checkbox: CheckboxStub } },
    });
}

function fullMatrix(): Record<string, unknown> {
    return {
        roles: [
            {
                id: 'role-1',
                name: 'Vertrieb',
                bypassesFieldPermissions: false,
            },
            {
                id: 'role-2',
                name: 'Administration',
                bypassesFieldPermissions: true,
            },
        ],
        fields: [
            { id: 'field-1', key: 'title', restricted: true },
            { id: 'field-2', key: 'note', restricted: false },
        ],
        grants: {
            'role-1': { 'field-1': { read: true, write: false } },
            'role-2': {},
        },
    };
}

function checkboxes(wrapper: Wrapper, label: string) {
    return wrapper.find(`[aria-label="${label}"]`);
}

describe('objectTypes/edit/Permissions', () => {
    it('shows every role of the tenant against every field of this object type', () => {
        const wrapper = mountMatrix(fullMatrix());

        expect(wrapper.text()).toContain('Vertrieb');
        expect(wrapper.text()).toContain('Administration');
        expect(
            wrapper.find('[data-testid="permission-row-title"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('[data-testid="permission-row-note"]').exists(),
        ).toBe(true);
    });

    it('separates the see tick from the edit tick of one role', () => {
        const wrapper = mountMatrix(fullMatrix());

        expect(
            checkboxes(wrapper, 'Vertrieb sieht title').attributes('checked'),
        ).toBeDefined();
        expect(
            checkboxes(wrapper, 'Vertrieb bearbeitet title').attributes(
                'checked',
            ),
        ).toBeUndefined();
    });

    it('ticks every right a role holds no restriction for', () => {
        const wrapper = mountMatrix(fullMatrix());

        expect(
            checkboxes(wrapper, 'Vertrieb sieht note').attributes('checked'),
        ).toBeDefined();
        expect(
            checkboxes(wrapper, 'Vertrieb bearbeitet note').attributes(
                'checked',
            ),
        ).toBeDefined();
    });

    it('offers no tick to change — the matrix is a read-only view', () => {
        const wrapper = mountMatrix(fullMatrix());
        const boxes = wrapper.findAll('input[type="checkbox"]');

        expect(boxes.length).toBe(8);
        expect(
            boxes.every((box) => box.attributes('disabled') !== undefined),
        ).toBe(true);
    });

    it('marks a role that bypasses field permissions', () => {
        const wrapper = mountMatrix(fullMatrix());

        expect(wrapper.text()).toContain('übergeht Feldrechte');
        expect(
            checkboxes(wrapper, 'Administration sieht title').attributes(
                'checked',
            ),
        ).toBeDefined();
    });

    it('counts the restricted fields of this object type', () => {
        const wrapper = mountMatrix(fullMatrix());

        expect(
            wrapper.find('[data-testid="permission-matrix-counter"]').text(),
        ).toBe('1 von 2 Feldern eingeschränkt');
    });

    it('reports an object type without fields as empty instead of an empty table', () => {
        const wrapper = mountMatrix({
            roles: fullMatrix().roles,
            fields: [],
            grants: {},
        });

        expect(
            wrapper.find('[data-testid="permission-matrix-empty"]').exists(),
        ).toBe(true);
        expect(wrapper.find('[data-testid="permission-matrix"]').exists()).toBe(
            false,
        );
    });
});
