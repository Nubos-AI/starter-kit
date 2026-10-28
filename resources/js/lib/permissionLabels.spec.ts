import { describe, expect, it } from 'vitest';
import {
    permissionActionLabel,
    permissionGroupLabel,
} from '@/lib/permissionLabels';

const CONFIGURED_ACTIONS = [
    'view',
    'create',
    'update',
    'delete',
    'invite',
    'remove',
    'manage',
    'password',
    'block',
    'configure',
    'decide',
    'reparent',
    'reset',
    'execute',
    'approve',
    'export',
    'import',
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
    it.each(CONFIGURED_ACTIONS)('names the action %s in German', (action) => {
        const label = permissionActionLabel(`members.${action}`, 'members');

        expect(label).not.toBe('');
        expect(label.toLowerCase()).not.toBe(action);
    });

    it('names the actions the UX test found in English', () => {
        expect(permissionActionLabel('members.password', 'members')).toBe(
            'Passwort setzen',
        );
        expect(permissionActionLabel('members.block', 'members')).toBe(
            'Sperren',
        );
        expect(permissionActionLabel('approvals.configure', 'approvals')).toBe(
            'Einrichten',
        );
        expect(permissionActionLabel('approvals.decide', 'approvals')).toBe(
            'Entscheiden',
        );
        expect(permissionActionLabel('teams.reparent', 'teams')).toBe(
            'Umhängen',
        );
    });

    it('names the three operations actions in German', () => {
        expect(permissionActionLabel('example.reset', 'example')).toBe(
            'Zurücksetzen',
        );
        expect(permissionActionLabel('promotions.execute', 'promotions')).toBe(
            'Ausführen',
        );
        expect(permissionActionLabel('promotions.approve', 'promotions')).toBe(
            'Genehmigen',
        );
    });

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
