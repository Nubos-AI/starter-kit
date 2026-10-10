import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import Form from '@/pages/relationshipTypes/Form.vue';
import { selectStubs } from '@/tests/selectStubs';

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
    router: {
        on: vi.fn(() => vi.fn()),
        visit: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

const CheckboxStub = {
    name: 'CheckboxStub',
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
        '<input type="checkbox" :disabled="disabled" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
};

const stubs = {
    ...selectStubs,
    Checkbox: CheckboxStub,
};

const objectTypeOptions = [
    { value: 'ot-departments', label: 'Departments' },
    { value: 'ot-companies', label: 'Companies' },
];

const cardinalities = [
    { value: 'many_to_many', label: 'Many To Many' },
    { value: 'one_to_many', label: 'One To Many' },
];

const cascadeBehaviors = [{ value: 'nullify', label: 'Nullify' }];

type Wrapper = ReturnType<typeof mount>;

function relationshipType(
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id: 'rt-1',
        key: 'parent-of',
        name: 'Parent of',
        inverse_name: 'Child of',
        from_object_type_id: 'ot-departments',
        to_object_type_id: 'ot-departments',
        cardinality: 'many_to_many',
        cascade_behavior: 'nullify',
        is_required: false,
        ...overrides,
    };
}

function mountCreateForm(): Wrapper {
    return mount(Form, {
        props: {
            mode: 'create',
            relationshipType: null,
            objectTypeOptions,
            cardinalities,
            cascadeBehaviors,
        },
        global: { stubs },
    });
}

function mountEditForm(payload: Record<string, unknown>): Wrapper {
    return mount(Form, {
        props: {
            mode: 'edit',
            relationshipType: payload as never,
            objectTypeOptions,
            cardinalities,
            cascadeBehaviors,
        },
        global: { stubs },
    });
}

function hierarchyBox(wrapper: Wrapper) {
    return wrapper.find('input[name="is_hierarchy"]');
}

function requiredBox(wrapper: Wrapper) {
    return wrapper.find('[data-testid="flag-is_required"]');
}

function requiredCarrier(wrapper: Wrapper) {
    return wrapper.find('input[name="is_required"]');
}

function isRequiredChecked(wrapper: Wrapper): boolean {
    return (requiredBox(wrapper).element as HTMLInputElement).checked;
}

function selectedValue(wrapper: Wrapper, selector: string): string {
    return (wrapper.find(selector).element as HTMLSelectElement).value;
}

describe('relationshipTypes/Form — hierarchy is none of its business', () => {
    it('offers no hierarchy flag at all, since the object type owns its carrier', () => {
        const wrapper = mountCreateForm();

        expect(hierarchyBox(wrapper).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Hierarchy');
    });

    it('offers no hierarchy flag when editing either', () => {
        const wrapper = mountEditForm(relationshipType());

        expect(hierarchyBox(wrapper).exists()).toBe(false);
    });
});

describe('relationshipTypes/Form — required flag', () => {
    it('pre-checks the flag when editing a required relationship type', () => {
        const wrapper = mountEditForm(relationshipType({ is_required: true }));

        expect(isRequiredChecked(wrapper)).toBe(true);
        expect(requiredCarrier(wrapper).attributes('value')).toBe('1');
    });

    it('leaves the flag unchecked when editing an optional relationship type', () => {
        const wrapper = mountEditForm(relationshipType({ is_required: false }));

        expect(isRequiredChecked(wrapper)).toBe(false);
    });

    it('keeps the flag checked after the user ticks it', async () => {
        const wrapper = mountCreateForm();

        await requiredBox(wrapper).setValue(true);

        expect(isRequiredChecked(wrapper)).toBe(true);
    });
});

describe('relationshipTypes/Form — unsaved changes', () => {
    it('keeps saving disabled until a field changes', async () => {
        const wrapper = mountEditForm(relationshipType());

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#rel-name').setValue('Parent of unit');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('treats a toggled checkbox as an unsaved change', async () => {
        const wrapper = mountEditForm(relationshipType());

        await requiredBox(wrapper).setValue(true);

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountEditForm(relationshipType());

        await wrapper.get('#rel-name').setValue('Parent of unit');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});

describe('relationshipTypes/Form — enum selects', () => {
    it('preselects the persisted cardinality and cascade behavior when editing', () => {
        const wrapper = mountEditForm(
            relationshipType({
                cardinality: 'one_to_many',
                cascade_behavior: 'nullify',
            }),
        );

        expect(selectedValue(wrapper, 'select[name="cardinality"]')).toBe(
            'one_to_many',
        );
        expect(selectedValue(wrapper, 'select[name="cascade_behavior"]')).toBe(
            'nullify',
        );
    });
});

describe('relationshipTypes/Form — the copy is German', () => {
    it('labels every control in German', () => {
        const text = mountCreateForm().text();

        expect(text).toContain('Ausgangs-Objekttyp');
        expect(text).toContain('Ziel-Objekttyp');
        expect(text).toContain('Bezeichnung');
        expect(text).toContain('Gegenbezeichnung');
        expect(text).toContain('Kardinalität');
        expect(text).toContain('Verhalten beim Löschen');
    });

    it('leaves no English label or description behind', () => {
        const text = mountCreateForm().text();

        for (const english of [
            'From object type',
            'To object type',
            'Inverse name',
            'Cardinality',
            'Cascade behavior',
            'Automations reference these in the Link Relation action.',
        ]) {
            expect(text).not.toContain(english);
        }
    });
});
