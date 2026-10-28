import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import englishMessages from '@/../lang/en/i18n.json';
import FormulaFieldEditor from '@/components/engine/objectType/FormulaFieldEditor.vue';
import { translationCatalogueKey } from '@/lib/i18n';
import { selectStubs } from '@/tests/selectStubs';
import type { FormulaResultType, ObjectTypeFieldRow } from '@/types/formulas';
import { formulaErrorLabels, isFormulaErrorValue } from '@/types/formulas';

interface EditorProps {
    fieldType: string;
    fields: ObjectTypeFieldRow[];
    currentKey?: string | null;
    initialFormula?: string | null;
    initialResultType?: FormulaResultType | null;
    errorMessage?: string;
}

type Wrapper = ReturnType<typeof mount>;

const formulaSelector = 'textarea[name="config[formula]"]';
const resultTypeSelector = 'select[name="config[result_type]"]';
const configSelector = '[name^="config\\["]';
const insertSelector = '[data-testid^="formula-field-"]';
const insertGroupSelector = '[aria-labelledby="field_formula_references"]';
const noReferenceText =
    'This object type carries no other field a formula can refer to.';

function field(
    key: string,
    overrides: Partial<ObjectTypeFieldRow> = {},
): ObjectTypeFieldRow {
    return {
        id: `id-${key}`,
        field_group_id: null,
        key,
        field_type: 'number',
        label: key,
        description: null,
        is_required: false,
        is_unique: false,
        is_searchable: false,
        is_translatable: false,
        is_encrypted: false,
        is_sortable: true,
        is_filterable: true,
        is_default_column: true,
        is_card_field: false,
        is_reserved: false,
        is_type_changeable: true,
        list_position: null,
        config: null,
        validation_rules: null,
        default_value: null,
        ...overrides,
    };
}

const catalogue: ObjectTypeFieldRow[] = [
    field('netto'),
    field('link_total', { field_type: 'rollup' }),
    field('brutto', {
        field_type: 'computed',
        config: { formula: '{netto} * 1,19', result_type: 'number' },
    }),
    field('draft_total', {
        field_type: 'computed',
        config: { formula: '{netto} + 1' },
    }),
    field('secret_note', { field_type: 'text_short', is_encrypted: true }),
    field('tags', { field_type: 'multi_select' }),
    field('children', { field_type: 'relation_has_many' }),
    field('partners', { field_type: 'relation_many_to_many' }),
    field('attachment', { field_type: 'file' }),
    field('location', { field_type: 'geo_address' }),
    field('self_reference'),
];

function mountEditor(overrides: Partial<EditorProps> = {}): Wrapper {
    return mount(FormulaFieldEditor, {
        props: {
            fieldType: 'computed',
            fields: [field('netto')],
            ...overrides,
        } satisfies EditorProps,
        global: {
            provide: {
                [translationCatalogueKey as symbol]: () => ({
                    locale: 'en',
                    fallbackLocale: 'en',
                    messages: { i18n: englishMessages },
                }),
            },
            stubs: selectStubs,
        },
    });
}

describe('FormulaFieldEditor', () => {
    it('renders the formula input and the result type select under the config names', () => {
        const wrapper = mountEditor();

        expect(wrapper.find(formulaSelector).exists()).toBe(true);
        expect(wrapper.find(resultTypeSelector).exists()).toBe(true);
        expect(wrapper.get(formulaSelector).attributes('name')).toBe(
            'config[formula]',
        );
        expect(wrapper.get(resultTypeSelector).attributes('name')).toBe(
            'config[result_type]',
        );
    });

    it('inserts a field key in single braces at the caret and leaves the caret behind the token', async () => {
        const wrapper = mountEditor({
            fields: [field('netto')],
            initialFormula: 'ROUND( ; 2)',
        });

        const textarea = wrapper.get<HTMLTextAreaElement>(formulaSelector);

        await nextTick();

        textarea.element.selectionStart = 6;
        textarea.element.selectionEnd = 6;

        await wrapper
            .get('[data-testid="formula-field-netto"]')
            .trigger('click');
        await nextTick();

        expect(textarea.element.value).toBe('ROUND({netto} ; 2)');
        expect(textarea.element.selectionStart).toBe(13);
        expect(textarea.element.selectionEnd).toBe(13);
    });

    it('shows the initial formula and result type on mount', () => {
        const wrapper = mountEditor({
            initialFormula: '{netto} * 1,19',
            initialResultType: 'number',
        });

        expect(
            wrapper.get<HTMLTextAreaElement>(formulaSelector).element.value,
        ).toBe('{netto} * 1,19');
        expect(
            wrapper.get<HTMLSelectElement>(resultTypeSelector).element.value,
        ).toBe('number');
    });

    it('renders no config input at all for a non-computed field type', () => {
        const computedHost = document.createElement('div');
        const textHost = document.createElement('div');

        document.body.appendChild(computedHost);
        document.body.appendChild(textHost);

        const computedWrapper = mount(FormulaFieldEditor, {
            attachTo: computedHost,
            props: {
                fieldType: 'computed',
                fields: [field('netto')],
            } satisfies EditorProps,
            global: {
                provide: {
                    [translationCatalogueKey as symbol]: () => ({
                        locale: 'en',
                        fallbackLocale: 'en',
                        messages: { i18n: englishMessages },
                    }),
                },
                stubs: selectStubs,
            },
        });

        const textWrapper = mount(FormulaFieldEditor, {
            attachTo: textHost,
            props: {
                fieldType: 'text_short',
                fields: [field('netto')],
            } satisfies EditorProps,
        });

        expect(computedHost.querySelectorAll(configSelector)).toHaveLength(2);
        expect(textHost.querySelectorAll(configSelector)).toHaveLength(0);
        expect(textHost.querySelector('textarea')).toBeNull();
        expect(textHost.querySelectorAll(insertSelector)).toHaveLength(0);

        computedWrapper.unmount();
        textWrapper.unmount();
        computedHost.remove();
        textHost.remove();
    });

    it('offers only referenceable foreign fields in the insert list', () => {
        const wrapper = mountEditor({
            fields: catalogue,
            currentKey: 'self_reference',
        });

        const offered = wrapper
            .findAll(insertSelector)
            .map((entry) => entry.attributes('data-testid'));

        expect(offered).toEqual([
            'formula-field-netto',
            'formula-field-link_total',
            'formula-field-brutto',
        ]);

        for (const hidden of [
            'draft_total',
            'secret_note',
            'tags',
            'children',
            'partners',
            'attachment',
            'location',
            'self_reference',
        ]) {
            expect(
                wrapper
                    .find(`[data-testid="formula-field-${hidden}"]`)
                    .exists(),
            ).toBe(false);
        }
    });

    it('replaces the insert list with the empty state when nothing is referenceable', () => {
        const withoutFields = mountEditor({ fields: [] });
        const fullyFilteredOut = mountEditor({
            fields: [
                field('secret_note', {
                    field_type: 'text_short',
                    is_encrypted: true,
                }),
                field('tags', { field_type: 'multi_select' }),
                field('draft_total', {
                    field_type: 'computed',
                    config: { formula: '{netto} + 1' },
                }),
                field('self_reference'),
            ],
            currentKey: 'self_reference',
        });

        for (const wrapper of [withoutFields, fullyFilteredOut]) {
            expect(wrapper.findAll(insertSelector)).toHaveLength(0);
            expect(wrapper.find(insertGroupSelector).exists()).toBe(false);
            expect(wrapper.text()).toContain(noReferenceText);
        }

        const withOneField = mountEditor({ fields: [field('netto')] });

        expect(withOneField.findAll(insertSelector)).toHaveLength(1);
        expect(withOneField.find(insertGroupSelector).exists()).toBe(true);
        expect(withOneField.text()).not.toContain(noReferenceText);
    });

    it('requires a result type and preselects none when no initial one was passed', () => {
        const wrapper = mountEditor();

        const select = wrapper.get<HTMLSelectElement>(resultTypeSelector);

        expect(select.attributes('required')).toBeDefined();
        expect(select.element.value).toBe('');
        expect(select.element.required).toBe(true);
    });

    it('shows the server error at the formula input and marks it invalid', () => {
        const message =
            'The character "#" at position 8 is not part of the formula language.';

        const withError = mountEditor({ errorMessage: message });
        const withoutError = mountEditor();

        expect(withError.text()).toContain(message);
        expect(withError.get(formulaSelector).attributes('aria-invalid')).toBe(
            'true',
        );
        expect(
            withoutError.get(formulaSelector).attributes('aria-invalid'),
        ).toBeUndefined();
        expect(withoutError.text()).not.toContain(message);
    });
});

describe('isFormulaErrorValue', () => {
    it('rejects a value that is not an object', () => {
        expect(isFormulaErrorValue('division_by_zero')).toBe(false);
        expect(isFormulaErrorValue(19)).toBe(false);
        expect(isFormulaErrorValue(true)).toBe(false);
        expect(isFormulaErrorValue(undefined)).toBe(false);
        expect(isFormulaErrorValue(() => 'division_by_zero')).toBe(false);
    });

    it('rejects null, which typeof reports as an object', () => {
        expect(isFormulaErrorValue(null)).toBe(false);
    });

    it('rejects an object that carries only one of the two keys', () => {
        expect(isFormulaErrorValue({})).toBe(false);
        expect(isFormulaErrorValue({ code: 'division_by_zero' })).toBe(false);
        expect(isFormulaErrorValue({ field_key: 'netto' })).toBe(false);
    });

    it('rejects a code that is not one of the known error codes', () => {
        expect(isFormulaErrorValue({ code: 19, field_key: null })).toBe(false);
        expect(isFormulaErrorValue({ code: null, field_key: null })).toBe(
            false,
        );
        expect(isFormulaErrorValue({ code: 'meltdown', field_key: null })).toBe(
            false,
        );
        expect(isFormulaErrorValue({ code: 'toString', field_key: null })).toBe(
            false,
        );
        expect(
            isFormulaErrorValue({ code: 'constructor', field_key: null }),
        ).toBe(false);
    });

    it('rejects a field key that is neither a string nor null', () => {
        expect(
            isFormulaErrorValue({ code: 'division_by_zero', field_key: 19 }),
        ).toBe(false);
        expect(
            isFormulaErrorValue({
                code: 'division_by_zero',
                field_key: undefined,
            }),
        ).toBe(false);
        expect(
            isFormulaErrorValue({ code: 'division_by_zero', field_key: {} }),
        ).toBe(false);
    });

    it('accepts every known error code with and without a field key', () => {
        for (const code of Object.keys(formulaErrorLabels)) {
            expect(isFormulaErrorValue({ code, field_key: 'netto' })).toBe(
                true,
            );
            expect(isFormulaErrorValue({ code, field_key: null })).toBe(true);
        }
    });

    it('narrows an accepted value to a usable error code', () => {
        const value: unknown = { code: 'type_mismatch', field_key: 'netto' };

        if (!isFormulaErrorValue(value)) {
            throw new Error('the guard must accept a well-formed error value');
        }

        expect(formulaErrorLabels[value.code]).toBe(
            'Ein Wert passt nicht zum erwarteten Typ',
        );
        expect(value.field_key).toBe('netto');
    });
});
