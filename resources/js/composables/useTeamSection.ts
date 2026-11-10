import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import type { TeamSectionKey } from '@/lib/teamSections';
import { teamSectionTitle } from '@/lib/teamSections';
import type { TeamSummary } from '@/types/teams';

export function useTeamSection(
    team: TeamSummary | null,
    section: TeamSectionKey,
): void {
    const { t } = useI18n();

    usePageBreadcrumbs(() => {
        if (team === null) {
            return [{ title: t('i18n.layouts.team.layout.new_team') }];
        }

        return section === 'details'
            ? [{ title: team.name }]
            : [
                  {
                      title: team.name,
                      href: TeamsController.edit({ team: team.id }),
                  },
                  { title: teamSectionTitle(section, t) },
              ];
    });
}
