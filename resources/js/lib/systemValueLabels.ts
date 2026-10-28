export const RELATION_CARDINALITY_LABELS = {
    one_to_many: 'Eins zu viele',
    many_to_many: 'Viele zu viele',
} as const;

export const STORAGE_STRATEGY_LABELS = {
    native: 'Eigene Tabelle',
    generic: 'Gemeinsame Tabelle',
} as const;

export const SYSTEM_ROLE_LABELS = {
    owner: 'Inhaber',
    admin: 'Administrator',
    member: 'Mitglied',
} as const;

export const SYSTEM_FIELD_LABELS = {
    owner_id: 'Zuständigkeit',
    team_id: 'Team',
    object_type_id: 'Objekttyp',
    stage_id: 'Stage',
    pipeline_id: 'Pipeline',
    business_key: 'Datensatznummer',
    deleted_at: 'Gelöscht am',
    created_by_id: 'Angelegt von',
    updated_by_id: 'Geändert von',
    merged_into_id: 'Zusammengeführt in',
    current_visibility: 'Sichtbarkeit',
    aging_age: 'Alter',
    aging_stage: 'Alterungsstufe',
} as const;

function labelOf(map: Record<string, string>, value: string): string {
    return map[value] ?? value;
}

export function relationCardinalityLabel(value: string): string {
    return labelOf(RELATION_CARDINALITY_LABELS, value);
}

export function storageStrategyLabel(value: string): string {
    return labelOf(STORAGE_STRATEGY_LABELS, value);
}

export function systemRoleLabel(name: string, isSystem: boolean): string {
    return isSystem ? labelOf(SYSTEM_ROLE_LABELS, name) : name;
}

export function systemFieldLabel(key: string): string | null {
    return SYSTEM_FIELD_LABELS[key as keyof typeof SYSTEM_FIELD_LABELS] ?? null;
}
