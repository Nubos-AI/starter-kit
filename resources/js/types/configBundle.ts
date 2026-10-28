export interface PlaceholderRequirement {
    artifact_key: string;
    label: string;
    current_value: string;
}

export interface ConfigIndexProps {
    can_export: boolean;
    export_reason: string | null;
    can_import: boolean;
    import_reason: string | null;
}

export interface ConfigPlaceholdersProps {
    run: { id: string };
    requirements: PlaceholderRequirement[];
    can_save: boolean;
    save_reason: string | null;
}
