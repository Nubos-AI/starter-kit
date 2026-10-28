import type { FieldType } from '@/types/fields';

export type FieldDefaultKind = 'none' | 'static' | 'dynamic';

export type FieldDefaultSource = 'today' | 'now' | 'current_user' | 'sequence';

export type FieldCrossOperator =
    | 'after'
    | 'after_or_equal'
    | 'before'
    | 'before_or_equal'
    | 'gt'
    | 'gte'
    | 'lt'
    | 'lte'
    | 'same'
    | 'different';

export interface FieldDefaultValue {
    kind?: FieldDefaultKind | null;
    value?: unknown;
    source?: FieldDefaultSource | null;
}

export interface FieldValidationRules {
    regex?: string | null;
    min?: number | string | null;
    max?: number | string | null;
    in?: string[] | null;
    cross?: Partial<Record<FieldCrossOperator, string>> | null;
}

export const selectFieldTypes: ReadonlyArray<FieldType> = [
    'single_select',
    'multi_select',
];

export const relationFieldTypes: ReadonlyArray<FieldType> = [
    'relation_has_many',
    'relation_many_to_many',
];

export const textualFieldTypes: ReadonlyArray<FieldType> = [
    'text_short',
    'text_long',
    'email',
    'phone',
    'url',
];

export const numericFieldTypes: ReadonlyArray<FieldType> = [
    'number',
    'decimal',
    'money',
];

export const temporalFieldTypes: ReadonlyArray<FieldType> = [
    'date',
    'datetime',
];

const defaultlessFieldTypes: ReadonlyArray<FieldType> = [
    'computed',
    'rollup',
    'file',
    'geo_address',
    ...relationFieldTypes,
];

export const fieldDefaultKindOptions: ReadonlyArray<{
    value: FieldDefaultKind;
    label: string;
}> = [
    { value: 'none', label: 'Kein Standardwert' },
    { value: 'static', label: 'Fester Wert' },
    { value: 'dynamic', label: 'Berechnet beim Anlegen' },
];

export const fieldDefaultSourceOptions: ReadonlyArray<{
    value: FieldDefaultSource;
    label: string;
}> = [
    { value: 'today', label: 'Heutiges Datum' },
    { value: 'now', label: 'Aktueller Zeitpunkt' },
    { value: 'current_user', label: 'Angemeldete Person' },
    { value: 'sequence', label: 'Datensatznummer' },
];

export const fieldCrossOperatorOptions: ReadonlyArray<{
    value: FieldCrossOperator;
    label: string;
}> = [
    { value: 'after', label: 'liegt nach' },
    { value: 'after_or_equal', label: 'liegt nicht vor' },
    { value: 'before', label: 'liegt vor' },
    { value: 'before_or_equal', label: 'liegt nicht nach' },
    { value: 'gt', label: 'ist größer als' },
    { value: 'gte', label: 'ist größer oder gleich' },
    { value: 'lt', label: 'ist kleiner als' },
    { value: 'lte', label: 'ist kleiner oder gleich' },
    { value: 'same', label: 'ist gleich' },
    { value: 'different', label: 'ist ungleich' },
];

export function supportsOptions(fieldType: FieldType): boolean {
    return selectFieldTypes.includes(fieldType);
}

export function supportsRelationTarget(fieldType: FieldType): boolean {
    return relationFieldTypes.includes(fieldType);
}

export function supportsDefaultValue(fieldType: FieldType): boolean {
    return !defaultlessFieldTypes.includes(fieldType);
}

export function supportsValidationRules(fieldType: FieldType): boolean {
    return (
        textualFieldTypes.includes(fieldType) ||
        numericFieldTypes.includes(fieldType) ||
        temporalFieldTypes.includes(fieldType)
    );
}

export function isTemporalField(fieldType: FieldType): boolean {
    return temporalFieldTypes.includes(fieldType);
}

export function isNumericField(fieldType: FieldType): boolean {
    return numericFieldTypes.includes(fieldType);
}

export function toFieldOptions(config: unknown): string[] {
    if (typeof config !== 'object' || config === null) {
        return [];
    }

    const raw = (config as { options?: unknown }).options;

    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.filter((option): option is string => typeof option === 'string');
}

export function toRelationshipTypeId(config: unknown): string {
    if (typeof config !== 'object' || config === null) {
        return '';
    }

    const raw = (config as { relationship_type_id?: unknown })
        .relationship_type_id;

    return typeof raw === 'string' ? raw : '';
}

export function toLookupObjectType(config: unknown): string {
    if (typeof config !== 'object' || config === null) {
        return '';
    }

    const raw = (config as { lookup_object_type?: unknown }).lookup_object_type;

    return typeof raw === 'string' ? raw : '';
}
