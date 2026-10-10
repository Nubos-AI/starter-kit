import type { BadgeVariants } from '@/components/ui/badge';

export type StatusVariant = NonNullable<BadgeVariants['variant']>;

export interface StatusEntry {
    label: string;
    variant: StatusVariant;
}

export type StatusMap = Record<string, StatusEntry>;

export const IMPORT_JOB_STATUS: StatusMap = {
    pending: { label: 'Ausstehend', variant: 'outline' },
    running: { label: 'Läuft', variant: 'info' },
    completed: { label: 'Abgeschlossen', variant: 'success' },
    failed: { label: 'Fehlgeschlagen', variant: 'destructive' },
};

export const USER_STATUS: StatusMap = {
    invited: { label: 'Eingeladen', variant: 'info' },
    accepted: { label: 'Aktiv', variant: 'success' },
    blocked: { label: 'Gesperrt', variant: 'destructive' },
    deleted: { label: 'Gelöscht', variant: 'secondary' },
};

export const WEBHOOK_STATUS: StatusMap = {
    pending: { label: 'Ausstehend', variant: 'outline' },
    active: { label: 'Aktiv', variant: 'success' },
    disabled: { label: 'Deaktiviert', variant: 'destructive' },
};

export const REPORT_EXECUTION_MODE: StatusMap = {
    viewer: { label: 'Eigene Rechte', variant: 'secondary' },
    definer: { label: 'Rechte des Erstellers', variant: 'info' },
};

export const DASHBOARD_VISIBILITY: StatusMap = {
    tenant_wide: { label: 'Mandantenweit', variant: 'info' },
    private: { label: 'Privat', variant: 'secondary' },
};

export const DASHBOARD_START_PAGE: StatusMap = {
    start_page: { label: 'Startseite', variant: 'info' },
};

export const SEGMENT_ORIGIN: StatusMap = {
    owner: { label: 'Eigen', variant: 'secondary' },
    shared: { label: 'Geteilt', variant: 'outline' },
};

export const REMINDER_STATE: StatusMap = {
    created: { label: 'Offen', variant: 'outline' },
    due: { label: 'Fällig', variant: 'warning' },
    completed: { label: 'Erledigt', variant: 'success' },
};

export const AGING_THRESHOLD_COLOR: StatusMap = {
    amber: { label: 'Warnung', variant: 'warning' },
    red: { label: 'Kritisch', variant: 'destructive' },
};

export const AGING_RULE_STATE: StatusMap = {
    active: { label: 'Aktiv', variant: 'success' },
    inactive: { label: 'Inaktiv', variant: 'outline' },
};

export const MERGE_RULE_STATE: StatusMap = {
    active: { label: 'Aktiv', variant: 'success' },
    inactive: { label: 'Inaktiv', variant: 'outline' },
};

export const MERGE_RULE_MODE: StatusMap = {
    allow: { label: 'Erlaubt', variant: 'success' },
    deny: { label: 'Verboten', variant: 'destructive' },
};

export const PROMOTION_RUN_STATUS: StatusMap = {
    draft: { label: 'Entwurf', variant: 'outline' },
    awaiting_approval: { label: 'Wartet auf Freigabe', variant: 'info' },
    approved: { label: 'Freigegeben', variant: 'info' },
    rejected: { label: 'Abgelehnt', variant: 'destructive' },
    applying: { label: 'Wird übernommen', variant: 'warning' },
    completed: { label: 'Abgeschlossen', variant: 'success' },
    failed: { label: 'Fehlgeschlagen', variant: 'destructive' },
    rolled_back: { label: 'Zurückgenommen', variant: 'secondary' },
};

export const PROMOTION_WRITE_ACTION: StatusMap = {
    created: { label: 'Angelegt', variant: 'success' },
    updated: { label: 'Geändert', variant: 'info' },
    removed: { label: 'Entfernt', variant: 'secondary' },
    skipped: { label: 'Übersprungen', variant: 'destructive' },
};

export const PROMOTION_DIFF_STATE: StatusMap = {
    added: { label: 'Neu', variant: 'success' },
    removed: { label: 'Entfernt', variant: 'destructive' },
    modified: { label: 'Geändert', variant: 'info' },
    unchanged: { label: 'Unverändert', variant: 'secondary' },
    conflicted: { label: 'Konflikt', variant: 'warning' },
};

export const PROMOTION_CONFLICT_STATE: StatusMap = {
    undecided: { label: 'Entscheidung nötig', variant: 'destructive' },
    decided: { label: 'Entschieden', variant: 'secondary' },
};

export const MAINTENANCE_LOCK_RELEASE: StatusMap = {
    automatic: { label: 'Automatisch', variant: 'secondary' },
    manual: { label: 'Von Hand', variant: 'info' },
    emergency: { label: 'Notaufhebung', variant: 'warning' },
};

export function resolveStatus(map: StatusMap, value: string): StatusEntry {
    return map[value] ?? { label: value, variant: 'outline' };
}

export const FIELD_RESTRICTION_STATE: StatusMap = {
    open: { label: 'Uneingeschränkt', variant: 'outline' },
    restricted: { label: 'Eingeschränkt', variant: 'warning' },
};
