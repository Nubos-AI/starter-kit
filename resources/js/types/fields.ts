import type { FieldValidationRules } from '@/types/fieldEditor';
import type { SelectOption } from '@/types/ui';

export type FieldType =
    | 'text_short'
    | 'text_long'
    | 'number'
    | 'decimal'
    | 'money'
    | 'date'
    | 'datetime'
    | 'boolean'
    | 'single_select'
    | 'multi_select'
    | 'relation_has_many'
    | 'relation_many_to_many'
    | 'email'
    | 'phone'
    | 'url'
    | 'file'
    | 'geo_address'
    | 'computed'
    | 'rollup';

export type FieldOption = SelectOption;

export interface FieldConfig {
    options?: Array<FieldOption | string>;
    [key: string]: unknown;
}

export interface FieldDefinition {
    key: string;
    field_group_id?: string | null;
    field_type: FieldType;
    label: string;
    description?: string | null;
    is_required: boolean;
    placeholder?: string | null;
    config?: FieldConfig | null;
    validation_rules?: FieldValidationRules | null;
    default_value?: unknown;
    is_sortable?: boolean;
    is_filterable?: boolean;
    is_default_column?: boolean;
    is_card_field?: boolean;
    list_position?: number;
}

export interface FieldValidationHints {
    required: boolean;
    maxLength?: number;
    min?: number;
    max?: number;
}

export interface FieldTypeOption {
    value: FieldType;
    label: string;
    description: string;
    category: string;
    categoryLabel: string;
    categoryPosition: number;
}
