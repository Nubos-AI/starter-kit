import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    permissionActionLabel,
    permissionGroupLabel,
} from '@/lib/permissionLabels';
import type { UiOptions } from '@/types/modules';

const page = vi.hoisted(() => ({
    props: { uiOptions: {} as UiOptions },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
}));

const CONFIGURED_ACTIONS: Array<[string, string]> = [
    ['view', 'Ansehen'],
    ['create', 'Anlegen'],
    ['update', 'Bearbeiten'],
    ['delete', 'Löschen'],
    ['invite', 'Einladen'],
    ['remove', 'Entfernen'],
    ['manage', 'Verwalten'],
    ['password', 'Passwort setzen'],
    ['block', 'Sperren'],
    ['configure', 'Einrichten'],
    ['decide', 'Entscheiden'],
    ['reparent', 'Umhängen'],
    ['reset', 'Zurücksetzen'],
    ['execute', 'Ausführen'],
    ['approve', 'Genehmigen'],
    ['export', 'Exportieren'],
    ['import', 'Importieren'],
];

const OPERATIONS_GROUP_LABELS: Array<[string, string]> = [
    ['promotions', 'Übernahmen'],
    ['config', 'Konfiguration'],
    ['maintenance', 'Wartungsmodus'],
];

const CONFIGURED_GROUP_LABELS: Array<[string, string]> = [
    ['members', 'Benutzer'],
    ['absences', 'Abwesenheiten'],
    ['organisation', 'Organisation'],
    ['permissions', 'Rechte'],
    ['roles', 'Rollen'],
    ['teams', 'Team'],
    ['tenants', 'Mandant'],
    ['object-types', 'Objekttypen'],
    ['reminder-types', 'Erinnerungstypen'],
    ['skills', 'Fähigkeiten'],
    ['automations', 'Automationen'],
    ['api-tokens', 'API-Token'],
    ['approvals', 'Genehmigungen'],
    ['quality-gates', 'Qualitäts-Gates'],
    ['routing', 'Zuweisungsregeln'],
    ...OPERATIONS_GROUP_LABELS,
];

describe('permissionLabels — every configured action carries a German label', () => {
    it.each(CONFIGURED_ACTIONS)(
        'names the action %s in German',
        (action, expected) => {
            expect(permissionActionLabel(`members.${action}`, 'members')).toBe(
                expected,
            );
        },
    );

    it.each(CONFIGURED_GROUP_LABELS)(
        'heads the configured group %s in German',
        (groupKey, expected) => {
            expect(permissionGroupLabel(groupKey)).toBe(expected);
        },
    );

    it('still reads an unknown action instead of dropping it', () => {
        expect(permissionActionLabel('members.frobnicate', 'members')).toBe(
            'Frobnicate',
        );
    });

    it('reads an object type group key that carries no configured label', () => {
        expect(permissionGroupLabel('companies')).toBe('Companies');
        expect(permissionGroupLabel('service-contracts')).toBe(
            'Service Contracts',
        );
    });
});

describe('permissionLabels — a module names its own actions', () => {
    afterEach(() => {
        page.props.uiOptions = {};
    });

    it('prefers the label a module contributes for the full permission name', () => {
        page.props.uiOptions = {
            'permissions.actions': [
                {
                    value: 'calendar.manage',
                    label: 'Alle Buchungsseiten verwalten',
                },
            ],
        };

        expect(permissionActionLabel('calendar.manage', 'calendar')).toBe(
            'Alle Buchungsseiten verwalten',
        );
    });

    it('keeps the default label for an action the module does not name', () => {
        page.props.uiOptions = {
            'permissions.actions': [
                {
                    value: 'calendar.manage',
                    label: 'Alle Buchungsseiten verwalten',
                },
            ],
        };

        expect(permissionActionLabel('calendar.view', 'calendar')).toBe(
            'Ansehen',
        );
        expect(permissionActionLabel('members.manage', 'members')).toBe(
            'Verwalten',
        );
    });
});

describe('permissionLabels — a module names its own permission groups', () => {
    afterEach(() => {
        page.props.uiOptions = {};
    });

    it('reads the group label a module contributes for a group the core does not know', () => {
        page.props.uiOptions = {
            'permissions.groups': [
                { value: 'calendar', label: 'Kalender und Buchungen' },
            ],
        };

        expect(permissionGroupLabel('calendar')).toBe('Kalender und Buchungen');
        expect(permissionGroupLabel('service-contracts')).toBe(
            'Service Contracts',
        );
    });

    it('keeps the core label of a group even when a module names it differently', () => {
        page.props.uiOptions = {
            'permissions.groups': [
                { value: 'promotions', label: 'Fremdes Etikett' },
            ],
        };

        expect(permissionGroupLabel('promotions')).toBe('Übernahmen');
    });
});
