export interface ObjectTypeRow {
    id: string;
    slug: string;
    name: string;
    is_system: boolean;
    storage_strategy: string;
    field_count: number;
    can_update: boolean;
    can_delete: boolean;
}

export interface RelationshipTypeRow {
    id: string;
    name: string;
    inverse_name: string;
    from_object_type: string;
    to_object_type: string;
    cardinality: string;
    can_update: boolean;
    can_delete: boolean;
}

export interface ObjectTypeSummary {
    id: string;
    key: string;
    slug: string;
    name: string;
    business_key_prefix: string | null;
    record_number_format: string | null;
    requires_deletion_reason: boolean;
    is_navigable: boolean;
    nav_icon: string;
    nav_position: number;
    retention_days: number | null;
    business_key_locked: boolean;
    is_system: boolean;
    storage_strategy: string;
    hierarchy_relationship_type_id: string | null;
}

export interface NavIconOption {
    value: string;
    label: string;
}
