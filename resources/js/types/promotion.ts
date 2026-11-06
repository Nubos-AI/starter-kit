export type ConflictResolutionValue = 'take_source' | 'keep_target';

export type PromotionDiffState =
    | 'added'
    | 'removed'
    | 'modified'
    | 'unchanged'
    | 'conflicted';

export interface PromotionRunRow {
    id: string;
    direction: string;
    direction_label: string;
    counterpart_label: string;
    status: string;
    artifact_count: number;
    triggered_by_name: string | null;
    created_at: string;
    can_view: boolean;
    can_update: boolean;
    update_reason: string | null;
    can_rollback: boolean;
    rollback_reason: string | null;
}

export interface PromotionReviewRun {
    id: string;
    status: string;
    direction: string;
    direction_label: string;
    counterpart_label: string;
    can_update: boolean;
    update_reason: string | null;
    can_submit: boolean;
    submit_reason: string | null;
    has_outdated_decisions: boolean;
    undecided_count: number;
}

export interface PromotionDiffRow {
    kind: string;
    key: string;
    state: PromotionDiffState;
    changed_paths: string[];
    is_selected: boolean;
    is_pulled_in: boolean;
    pulled_in_reason: string | null;
    refusal_reason: string | null;
    decision: ConflictResolutionValue | null;
    can_select: boolean;
    select_reason: string | null;
    can_overwrite: boolean;
    overwrite: boolean;
    overwrite_consequence: string | null;
}

export interface PromotionReportResult {
    kind: string;
    key: string;
    action: string;
    notes: string[];
}

export interface PromotionReport {
    error: string | null;
    written_count: number;
    skipped_count: number;
    notes: string[];
    results: PromotionReportResult[];
}

export interface PromotionRenameHint {
    from_key: string;
    to_key: string;
    message: string;
}

export interface PromotionDiffGroup {
    kind: string;
    unchanged_count: number;
    rename_hints: PromotionRenameHint[];
    rows: PromotionDiffRow[];
}

export const ARTIFACT_KIND_LABELS: Readonly<Record<string, string>> = {
    'object-types': 'Objekttypen',
    'field-groups': 'Feldgruppen',
    'field-definitions': 'Felder',
    'relationship-types': 'Beziehungsarten',
    pipelines: 'Pipelines',
    'pipeline-stages': 'Stages',
    'stage-transitions': 'Stage-Übergänge',
    'transition-gates': 'Übergangsbedingungen',
    'field-dependencies': 'Feldabhängigkeiten',
    'merge-rules': 'Zusammenführungsregeln',
    'reminder-types': 'Erinnerungsarten',
    roles: 'Rollen',
    'role-permissions': 'Rollenrechte',
    'field-permissions': 'Feldrechte',
    'team-record-access-rules': 'Team-Zugriffsregeln',
    automations: 'Abläufe',
    'automation-templates': 'Ablaufvorlagen',
    'notification-rules': 'Benachrichtigungsregeln',
    'notification-type-defaults': 'Benachrichtigungsvorgaben',
    'webhook-subscriptions': 'Webhook-Abonnements',
    reports: 'Auswertungen',
    dashboards: 'Dashboards',
    'dashboard-widgets': 'Dashboard-Kacheln',
    goals: 'Ziele',
    segments: 'Segmente',
    'document-templates': 'Dokumentvorlagen',
    'export-field-presets': 'Export-Feldvorlagen',
    'import-mapping-presets': 'Import-Zuordnungsvorlagen',
    'aging-rules': 'Alterungsregeln',
    skills: 'Fähigkeiten',
} as const;
