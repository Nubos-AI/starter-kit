import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ObjectTypeFieldDrawer from '@/components/engine/objectType/ObjectTypeFieldDrawer.vue';
import { selectStubs } from '@/tests/selectStubs';
import type { FieldGroupRow } from '@/types/fieldGroups';
import type { FieldTypeOption } from '@/types/fields';
import type { ObjectTypeFieldRow } from '@/types/formulas';

const { FormStub, formErrors } = vi.hoisted(() => {
    const formErrors: { value: Record<string, string> } = { value: {} };

    return {
        formErrors,
        FormStub: {
            name: 'FormStub',
            props: ['action', 'method', 'options', 'resetOnSuccess'],
            setup: () => ({ formErrors }),
            template:
                '<form><slot :errors="formErrors.value" :processing="false" /></form>',
        },
    };
});

vi.mock('@inertiajs/vue3', () => ({
    Form: FormStub,
    router: { put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}));

const passthrough = { template: '<div><slot /></div>' };

const textShortOption = {
    value: 'text_short',
    label: 'Kurzer Text',
    description: 'Eine Zeile Text.',
    category: 'text',
    categoryLabel: 'Text',
    categoryPosition: 1,
} as FieldTypeOption;

const computedOption = {
    value: 'computed',
    label: 'Formel',
    description: 'Berechnet den Wert.',
    category: 'calculated',
    categoryLabel: 'Berechnet',
    categoryPosition: 6,
} as FieldTypeOption;

const stubs = {
    ...selectStubs,
    Sheet: passthrough,
    SheetContent: passthrough,
    SheetHeader: passthrough,
    SheetTitle: passthrough,
    SheetDescription: passthrough,
    SheetFooter: passthrough,
    FormulaFieldEditor: true,
    RollupFieldEditor: true,
    FieldOptionsEditor: true,
    FieldValidationRulesEditor: true,
    FieldDefaultValueEditor: true,
    FieldIndexingSection: true,
    ConfirmDialog: true,
};

const address: FieldGroupRow = {
    id: 'fg-address',
    key: 'address',
    label: 'Adresse',
    description: null,
    position: 2,
};

const contact: FieldGroupRow = {
    id: 'fg-contact',
    key: 'contact',
    label: 'Kontakt',
    description: null,
    position: 1,
};

function field(
    fieldGroupId: string | null,
    description: string | null = null,
): ObjectTypeFieldRow {
    return {
        id: 'fd-street',
        field_group_id: fieldGroupId,
        key: 'street',
        field_type: 'text_short',
        label: 'Straße',
        description,
        is_required: false,
        is_unique: false,
        is_searchable: false,
        is_translatable: false,
        is_encrypted: false,
        is_sortable: false,
        is_filterable: false,
        is_default_column: false,
        is_card_field: false,
        is_reserved: false,
        is_type_changeable: true,
        list_position: null,
        config: null,
        validation_rules: null,
        default_value: null,
    };
}

async function mountDrawer(
    mode: 'create' | 'edit' = 'create',
    row: ObjectTypeFieldRow | null = null,
    pick: string = 'text_short',
) {
    const wrapper = mountRaw(mode, row);

    if (mode === 'create') {
        await wrapper
            .get(`[data-field-type-option="${pick}"]`)
            .trigger('click');
    }

    return wrapper;
}

function mountRaw(
    mode: 'create' | 'edit' = 'create',
    row: ObjectTypeFieldRow | null = null,
) {
    return mount(ObjectTypeFieldDrawer, {
        props: {
            open: true,
            objectTypeSlug: 'companies',
            fieldTypes: [textShortOption, computedOption],
            objectTypeOptions: [],
            groups: [address, contact],
            mode,
            field: row,
            fields: [],
            rollupTargets: [],
        },
        global: { stubs },
    });
}

function groupSelect(wrapper: Awaited<ReturnType<typeof mountDrawer>>) {
    return wrapper.get('[data-field-group-select]');
}

function submittedGroup(
    wrapper: Awaited<ReturnType<typeof mountDrawer>>,
): string {
    return (
        wrapper.get('input[name="field_group_id"]').element as HTMLInputElement
    ).value;
}

describe('ObjectTypeFieldDrawer — the field group', () => {
    it('offers every group in position order behind the ungrouped entry', async () => {
        const options = groupSelect(await mountDrawer())
            .findAll('option')
            .map((option) => option.text());

        expect(options).toEqual(['Ohne Gruppe', 'Kontakt', 'Adresse']);
    });

    it('submits an empty group for a new field, so the server stores none', async () => {
        expect(submittedGroup(await mountDrawer())).toBe('');
    });

    it('preselects the group a field already carries', async () => {
        expect(
            submittedGroup(await mountDrawer('edit', field(address.id))),
        ).toBe(address.id);
    });

    it('submits the group the user picks', async () => {
        const wrapper = await mountDrawer();

        await groupSelect(wrapper).setValue(address.id);

        expect(submittedGroup(wrapper)).toBe(address.id);
    });

    it('submits an empty group again once the user clears it', async () => {
        const wrapper = await mountDrawer('edit', field(address.id));

        await groupSelect(wrapper).setValue('none');

        expect(submittedGroup(wrapper)).toBe('');
    });
});

describe('ObjectTypeFieldDrawer — the description', () => {
    it('offers an empty description for a new field', async () => {
        const textarea = (await mountDrawer()).get<HTMLTextAreaElement>(
            '#field_description',
        );

        expect(textarea.attributes('name')).toBe('description');
        expect(textarea.element.value).toBe('');
    });

    it('shows the description a field already carries', async () => {
        const wrapper = await mountDrawer(
            'edit',
            field(null, 'Straße und Hausnummer.'),
        );

        expect(
            wrapper.get<HTMLTextAreaElement>('#field_description').element
                .value,
        ).toBe('Straße und Hausnummer.');
    });
});

describe('ObjectTypeFieldDrawer — the tab layout', () => {
    beforeEach(() => {
        formErrors.value = {};
    });

    it('keeps every panel mounted so an inactive tab still submits its values', async () => {
        const wrapper = await mountDrawer();

        expect(
            wrapper.find('[data-field-tab-panel="validation"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('[data-field-tab-panel="behaviour"]').exists(),
        ).toBe(true);
        expect(wrapper.find('input[name="field_group_id"]').exists()).toBe(
            true,
        );
    });

    it('offers the calculation tab only for a calculated field', async () => {
        expect(
            (await mountDrawer())
                .find('[data-field-tab="calculation"]')
                .exists(),
        ).toBe(false);

        const wrapper = await mountDrawer('edit', {
            ...field(null),
            field_type: 'computed',
        });

        expect(wrapper.find('[data-field-tab="calculation"]').exists()).toBe(
            true,
        );
    });

    it('marks the tab that carries an error the user cannot see', async () => {
        formErrors.value = { validation_rules: 'Ungültige Regel.' };

        const wrapper = await mountDrawer();

        expect(
            wrapper
                .get('[data-field-tab="validation"]')
                .attributes('data-has-error'),
        ).toBe('true');
        expect(
            wrapper
                .get('[data-field-tab="general"]')
                .attributes('data-has-error'),
        ).toBeUndefined();
    });

    it('offers the recompute action only for an existing calculated field', async () => {
        expect(
            (await mountDrawer()).find('[data-field-recompute]').exists(),
        ).toBe(false);

        const wrapper = await mountDrawer('edit', {
            ...field(null),
            field_type: 'computed',
        });

        expect(wrapper.find('[data-field-recompute]').exists()).toBe(true);
    });
});

describe('ObjectTypeFieldDrawer — choosing the type', () => {
    beforeEach(() => {
        formErrors.value = {};
    });

    it('asks for the type before it shows the form', () => {
        const wrapper = mountRaw();

        expect(wrapper.find('[data-field-type-picker]').exists()).toBe(true);
        expect(wrapper.find('[data-field-tab="general"]').exists()).toBe(false);
        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
    });

    it('shows the form and submits the picked type once it is chosen', async () => {
        const wrapper = await mountDrawer('create', null, 'computed');

        expect(wrapper.find('[data-field-type-picker]').exists()).toBe(false);
        expect(wrapper.get('[data-field-type-chosen]').text()).toBe('Formel');
        expect(
            (
                wrapper.get('input[name="field_type"]')
                    .element as HTMLInputElement
            ).value,
        ).toBe('computed');
        expect(wrapper.find('[data-field-tab="calculation"]').exists()).toBe(
            true,
        );
    });

    it('lets the user go back to the type gallery', async () => {
        const wrapper = await mountDrawer();

        await wrapper.get('[data-field-type-change]').trigger('click');

        expect(wrapper.find('[data-field-type-picker]').exists()).toBe(true);
    });

    it('never asks for the type again when an existing field is edited', () => {
        const wrapper = mountRaw('edit', field(null));

        expect(wrapper.find('[data-field-type-picker]').exists()).toBe(false);
        expect(wrapper.find('[data-field-tab="general"]').exists()).toBe(true);
    });
});
