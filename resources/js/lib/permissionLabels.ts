import { sharedModuleOptions } from '@/lib/modules';

const ACTION_LABELS: Record<string, string> = {
    view: 'Ansehen',
    create: 'Anlegen',
    update: 'Bearbeiten',
    delete: 'Löschen',
    import: 'Importieren',
    export: 'Exportieren',
    invite: 'Einladen',
    remove: 'Entfernen',
    manage: 'Verwalten',
    password: 'Passwort setzen',
    block: 'Sperren',
    configure: 'Einrichten',
    decide: 'Entscheiden',
    reparent: 'Umhängen',
    reset: 'Zurücksetzen',
    execute: 'Ausführen',
    approve: 'Genehmigen',
    'watchers.manage': 'Beobachter verwalten',
    'rules.manage': 'Automationen verwalten',
    'audit.view': 'Prüfhistorie ansehen',
};

const GROUP_LABELS: Record<string, string> = {
    members: 'Benutzer',
    absences: 'Abwesenheiten',
    organisation: 'Organisation',
    permissions: 'Rechte',
    roles: 'Rollen',
    teams: 'Team',
    tenants: 'Mandant',
    'object-types': 'Objekttypen',
    'reminder-types': 'Erinnerungstypen',
    'activity-types': 'Aktivitätstypen',
    skills: 'Fähigkeiten',
    automations: 'Automationen',
    'api-tokens': 'API-Token',
    approvals: 'Genehmigungen',
    'quality-gates': 'Qualitäts-Gates',
    routing: 'Zuweisungsregeln',
    promotions: 'Übernahmen',
    config: 'Konfiguration',
    maintenance: 'Wartungsmodus',
};

function headline(value: string): string {
    return value
        .split(/[.\-_]/)
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export function permissionGroupLabel(groupKey: string): string {
    return (
        GROUP_LABELS[groupKey] ??
        sharedModuleOptions('permissions.groups').find(
            (option) => option.value === groupKey,
        )?.label ??
        headline(groupKey)
    );
}

export function permissionActionLabel(name: string, groupKey: string): string {
    const prefix = `${groupKey}.`;
    const action = name.startsWith(prefix) ? name.slice(prefix.length) : name;

    return ACTION_LABELS[action] ?? headline(action);
}
