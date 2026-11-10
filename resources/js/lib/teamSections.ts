import TeamAccessRulesController from '@/actions/App/Http/Controllers/Teams/TeamAccessRulesController';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import type { NavItem } from '@/types/navigation';

export const TEAM_SECTION_KEYS = [
    'details',
    'members',
    'roles',
    'deniedPermissions',
    'accessRules',
] as const;

export type TeamSectionKey = (typeof TEAM_SECTION_KEYS)[number];

export interface TeamSectionItem {
    key: TeamSectionKey;
    title: string;
    href: NavItem['href'] | null;
}

const TITLE_KEYS: Record<TeamSectionKey, string> = {
    details: 'i18n.pages.teams.form.details',
    members: 'i18n.pages.teams.form.members',
    roles: 'i18n.pages.teams.form.roles',
    deniedPermissions: 'i18n.pages.teams.form.denied_permissions',
    accessRules: 'i18n.layouts.team.layout.record_access',
};

export function teamSectionTitle(
    key: TeamSectionKey,
    t: (key: string) => string,
): string {
    return t(TITLE_KEYS[key]);
}

function sectionHref(
    key: TeamSectionKey,
    teamId: string,
): NonNullable<NavItem['href']> {
    const args = { team: teamId };

    switch (key) {
        case 'details':
            return TeamsController.edit(args);
        case 'members':
            return TeamsController.members(args);
        case 'roles':
            return TeamsController.roles(args);
        case 'deniedPermissions':
            return TeamsController.deniedPermissions(args);
        case 'accessRules':
            return TeamAccessRulesController.index(args);
    }
}

export function teamSectionItems(
    teamId: string | null,
    t: (key: string) => string,
): TeamSectionItem[] {
    return TEAM_SECTION_KEYS.map((key) => ({
        key,
        title: teamSectionTitle(key, t),
        href:
            teamId !== null
                ? sectionHref(key, teamId)
                : key === 'details'
                  ? TeamsController.create()
                  : null,
    }));
}
