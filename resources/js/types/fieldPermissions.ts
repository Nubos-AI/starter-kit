export interface FieldPermissionEntry {
    id: string;
    key: string;
    read: boolean;
    write: boolean;
}

export interface FieldGrantDraft {
    read: boolean;
    write: boolean;
}

export type FieldPermissionPayloadRow = {
    field_definition_id: string;
    can_read: boolean;
    can_write: boolean;
};

export interface FieldPermissionChange {
    fieldDefinitionId: string;
    fieldKey: string;
    canRead: boolean;
    canWrite: boolean;
}

export interface FieldPermissionDelta {
    changes: FieldPermissionChange[];
    payload: FieldPermissionPayloadRow[];
}

export interface FieldPermissionDeltaOptions {
    isEscalated: boolean;
}

export interface FieldPermissionObjectType {
    id: string;
    key: string;
    slug: string;
    name: string;
}

export interface FieldPermissionGroup {
    objectType: FieldPermissionObjectType;
    fields: FieldPermissionEntry[];
}

export interface FieldPermissionMatrixRole {
    id: string;
    name: string;
    bypassesFieldPermissions: boolean;
}

export interface FieldPermissionMatrixField {
    id: string;
    key: string;
    restricted: boolean;
}

export interface FieldPermissionMatrix {
    roles: FieldPermissionMatrixRole[];
    fields: FieldPermissionMatrixField[];
    grants: Record<string, Record<string, FieldGrantDraft>>;
}
