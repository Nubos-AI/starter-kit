import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import { teamSectionTitle } from '@/domains/workspace-access/data';
import type { TeamSectionKey } from '@/domains/workspace-access/data';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import type { TeamSummary } from '@/shared/types/teams';

export function useTeamSection(team: TeamSummary | null, section: TeamSectionKey): void {
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
