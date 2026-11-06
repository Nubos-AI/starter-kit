import type { FieldDefaultValue, FieldValidationRules } from './fieldEditor';
import type { FieldType } from './fields';

export type FormulaResultType = 'number' | 'text' | 'date' | 'boolean';

export type FormulaErrorCode =
    | 'division_by_zero'
    | 'unknown_field_reference'
    | 'type_mismatch'
    | 'invalid_argument_count'
    | 'not_a_number'
    | 'invalid_date'
    | 'evaluation_limit_exceeded'
    | 'invalid_configuration';

export type BackfillStatus =
    | 'pending'
    | 'running'
    | 'completed'
    | 'cancelled'
    | 'failed';

export interface FormulaErrorValue {
    code: FormulaErrorCode;
    field_key: string | null;
}

export interface FormulaFieldConfig {
    formula?: string | null;
    result_type?: FormulaResultType | null;
    [key: string]: unknown;
}

export interface ObjectTypeFieldRow {
    id: string;
    field_group_id: string | null;
    key: string;
    field_type: FieldType;
    label: string;
    description: string | null;
    is_required: boolean;
    is_unique: boolean;
    is_searchable: boolean;
    is_translatable: boolean;
    is_encrypted: boolean;
    is_sortable: boolean;
    is_filterable: boolean;
    is_default_column: boolean;
    is_card_field: boolean;
    is_reserved: boolean;
    is_type_changeable: boolean;
    list_position: number | null;
    config: FormulaFieldConfig | null;
    validation_rules: FieldValidationRules | null;
    default_value: FieldDefaultValue | null;
}

export const formulaErrorLabels = {
    division_by_zero: 'Division durch null',
    unknown_field_reference:
        'Die Formel verweist auf ein Feld, das es nicht gibt',
    type_mismatch: 'Ein Wert passt nicht zum erwarteten Typ',
    invalid_argument_count: 'Eine Funktion hat die falsche Anzahl Argumente',
    not_a_number: 'Ein Wert ließ sich nicht als Zahl lesen',
    invalid_date: 'Ein Wert ließ sich nicht als Datum lesen',
    evaluation_limit_exceeded:
        'Die Formel hat die maximale Anzahl Rechenschritte überschritten',
    invalid_configuration:
        'Das Formelfeld hat keine brauchbare Formel und keinen Ergebnistyp',
} as const satisfies Record<FormulaErrorCode, string>;

export const formulaResultTypeOptions: ReadonlyArray<{
    value: FormulaResultType;
    label: string;
}> = [
    { value: 'number', label: 'Number' },
    { value: 'text', label: 'Text' },
    { value: 'date', label: 'Date' },
    { value: 'boolean', label: 'Boolean' },
];

export const referenceableFieldTypes: ReadonlyArray<FieldType> = [
    'number',
    'decimal',
    'money',
    'rollup',
    'text_short',
    'text_long',
    'email',
    'phone',
    'url',
    'single_select',
    'date',
    'datetime',
    'boolean',
    'computed',
];

export function isFormulaErrorValue(
    value: unknown,
): value is FormulaErrorValue {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    if (!('code' in value) || !('field_key' in value)) {
        return false;
    }

    const { code, field_key: fieldKey } = value;

    return (
        typeof code === 'string' &&
        Object.hasOwn(formulaErrorLabels, code) &&
        (fieldKey === null || typeof fieldKey === 'string')
    );
}

export function referenceableFields(
    fields: ObjectTypeFieldRow[],
    currentKey: string | null,
): ObjectTypeFieldRow[] {
    return fields.filter((field) => {
        if (field.key === currentKey || field.is_encrypted) {
            return false;
        }

        if (!referenceableFieldTypes.includes(field.field_type)) {
            return false;
        }

        return (
            field.field_type !== 'computed' ||
            (field.config?.result_type ?? null) !== null
        );
    });
}
