import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import Details from '@/pages/objectTypes/edit/Details.vue';
import { selectStubs } from '@/tests/selectStubs';

const { FormStub } = vi.hoisted(() => ({
    FormStub: {
        name: 'FormStub',
        props: ['transform', 'action', 'method', 'onSuccess'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
}));

const { pageState } = vi.hoisted(() => ({
    pageState: {
        url: '/engine/object-types/departments/edit',
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
    Form: FormStub,
    router: {
        on: vi.fn(() => vi.fn()),
        visit: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
    usePage: () => pageState,
}));

const hierarchyHint =
    'Die Über- und Unterordnung wird beim Aktivieren automatisch angelegt und beim Deaktivieren samt der bestehenden Zuordnungen entfernt.';

const CheckboxStub = {
    name: 'CheckboxStub',
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
        '<input type="checkbox" :disabled="disabled" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
};

const UiExtensionPointStub = {
    name: 'UiExtensionPoint',
    props: ['name', 'context'],
    template:
        '<button type="button" data-extension-point :data-point="name" :data-object-type="context.objectType?.slug ?? \'\'" @click="context.setModuleValue(\'is_featured\', true)" />',
};

const stubs = {
    ...selectStubs,
    Checkbox: CheckboxStub,
    UiExtensionPoint: UiExtensionPointStub,
};

type Wrapper = ReturnType<typeof mount>;

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
        is_navigable: true,
        nav_icon: 'database',
        nav_position: 0,
        ...overrides,
    };
}

const navIcons = [
    { value: 'database', label: 'Datenbank' },
    { value: 'users', label: 'Personen' },
    { value: 'award', label: 'Auszeichnung' },
];

function mountDetails(
    payload: Record<string, unknown> = objectType(),
): Wrapper {
    return mount(Details, {
        props: { objectType: payload as never, navIcons },
        global: { stubs },
    });
}

function mountCreate(): Wrapper {
    return mount(Details, {
        props: { objectType: null, navIcons },
        global: { stubs },
    });
}

function hierarchyCarrier(wrapper: Wrapper) {
    return wrapper.findAll('input[name="hierarchy_enabled"]');
}

function checkbox(wrapper: Wrapper) {
    return wrapper.find('[data-testid="flag-hierarchy_enabled"]');
}

function carrierSelect(wrapper: Wrapper) {
    return wrapper.find('select[name="hierarchy_relationship_type_id"]');
}

describe('objectTypes/edit/Details — creating', () => {
    it('asks for the name, the key and the business key parts', () => {
        const wrapper = mountCreate();

        expect(wrapper.find('#name').exists()).toBe(true);
        expect(wrapper.find('#key').exists()).toBe(true);
        expect(wrapper.find('#business_key_prefix').exists()).toBe(true);
        expect(
            (wrapper.get('#record_number_format').element as HTMLInputElement)
                .value,
        ).toBe('##########');
    });

    it('leaves out everything that only exists after saving', () => {
        const wrapper = mountCreate();

        expect(hierarchyCarrier(wrapper)).toHaveLength(0);
        expect(carrierSelect(wrapper).exists()).toBe(false);
    });

    it('keeps the key out of the way once the object type is saved', () => {
        expect(mountDetails().find('#key').exists()).toBe(false);
    });

    it('previews the business key once a prefix is given', async () => {
        const wrapper = mountCreate();

        await wrapper.get('#business_key_prefix').setValue('CO');

        expect(wrapper.text()).toContain('CO-0000000000');
    });

    it('keeps saving disabled until a field changes', async () => {
        const wrapper = mountCreate();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#name').setValue('Units');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountCreate();

        await wrapper.get('#name').setValue('Units');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});

describe('objectTypes/edit/Details — hierarchy opt-in', () => {
    it('always submits hierarchy_enabled through its hidden carrier', () => {
        const inputs = hierarchyCarrier(mountDetails());

        expect(inputs).toHaveLength(1);
        expect(inputs[0].attributes('type')).toBe('hidden');
        expect(inputs[0].attributes('value')).toBe('0');
    });

    it('asks for nothing but the checkbox, since the carrier is built for us', () => {
        const wrapper = mountDetails();

        expect(carrierSelect(wrapper).exists()).toBe(false);
        expect(checkbox(wrapper).attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain(hierarchyHint);
    });

    it('pre-checks the checkbox of an active hierarchy', () => {
        const wrapper = mountDetails(
            objectType({ hierarchy_relationship_type_id: 'rt-parent-of' }),
        );

        expect((checkbox(wrapper).element as HTMLInputElement).checked).toBe(
            true,
        );
        expect(hierarchyCarrier(wrapper)[0].attributes('value')).toBe('1');
    });

    it('omits the hierarchy controls for a read-only system object type', () => {
        const wrapper = mountDetails(objectType({ is_system: true }));

        expect(hierarchyCarrier(wrapper)).toHaveLength(0);
        expect(wrapper.text()).toContain('departments');
    });
});

describe('objectTypes/edit/Details — unsaved changes', () => {
    it('keeps saving disabled until a field changes', async () => {
        const wrapper = mountDetails();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#name').setValue('Units');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('treats a ticked checkbox as an unsaved change', async () => {
        const wrapper = mountDetails();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await checkbox(wrapper).setValue(true);

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountDetails();

        await wrapper.get('#name').setValue('Units');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });

    it('offers no form actions for a read-only system object type', () => {
        const wrapper = mountDetails(objectType({ is_system: true }));

        expect(wrapper.find('[data-form-save]').exists()).toBe(false);
    });
});

describe('objectTypes/edit/Details — navigation', () => {
    it('renders the navigation flag, position and icon with their persisted values', () => {
        const wrapper = mountDetails(
            objectType({
                is_navigable: false,
                nav_position: 7,
                nav_icon: 'users',
            }),
        );

        expect(
            (
                wrapper.get('[data-testid="flag-is_navigable"]')
                    .element as HTMLInputElement
            ).checked,
        ).toBe(false);
        expect(
            (wrapper.get('#nav_position').element as HTMLInputElement).value,
        ).toBe('7');
        expect(
            wrapper.get('select[name="nav_icon"]').element as HTMLSelectElement,
        ).toBeTruthy();
    });

    it('offers exactly the icon options handed over by the server', () => {
        const options = mountDetails()
            .get('select[name="nav_icon"]')
            .findAll('option');

        expect(options.map((option) => option.attributes('value'))).toEqual([
            'database',
            'users',
            'award',
        ]);
    });

    it('marks the form dirty when the nav icon changes', async () => {
        const wrapper = mountDetails();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('select[name="nav_icon"]').setValue('award');
        await nextTick();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('leaves the navigation fields out while creating', () => {
        const wrapper = mountCreate();

        expect(wrapper.find('#nav_position').exists()).toBe(false);
        expect(wrapper.find('select[name="nav_icon"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="flag-is_navigable"]').exists()).toBe(
            false,
        );
    });
});

describe('objectTypes/edit/Details — module fields', () => {
    it('offers modules a place for their own object type fields', () => {
        const point = mountDetails().get('[data-extension-point]');

        expect(point.attributes('data-point')).toBe(
            'object-types.details.fields',
        );
        expect(point.attributes('data-object-type')).toBe('departments');
    });

    it('offers the same place while an object type is being created', () => {
        expect(mountCreate().find('[data-extension-point]').exists()).toBe(
            true,
        );
    });

    it('counts a change a module field makes as unsaved', async () => {
        const wrapper = mountDetails();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('[data-extension-point]').trigger('click');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });
});
