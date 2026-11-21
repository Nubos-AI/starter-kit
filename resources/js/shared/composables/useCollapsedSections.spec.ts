import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import UserPreferencesController from '@/actions/App/Http/Controllers/Preferences/UserPreferencesController';
import { RELATIONS_PANEL_ID } from '@/domains/business-data/feature-records/utils/recordPanels';
import { useCollapsedSections } from '@/shared/composables/useCollapsedSections';
import {
  hydrateObjectTypePreferences,
  hydratePreferences,
  useUserPreferences,
} from '@/shared/composables/useUserPreferences';
import { serveJson } from '@/shared/testing/msw';
import { emptyPreferenceDocument } from '@/shared/testing/preferences';
import { emptyObjectTypePreferences } from '@/shared/types/preferences';
import type { PreferenceDocument } from '@/shared/types/preferences';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const OBJECT_TYPE_ID = '01JD9K2M4P7QR8XKAV0T3ZC5NE';

const updateRoute = UserPreferencesController.update();

function documentWith(sections: string[]): PreferenceDocument {
  const next = emptyPreferenceDocument();
  next.objectTypes[OBJECT_TYPE_ID] = {
    ...emptyObjectTypePreferences(),
    collapsedSections: sections,
  };

  return next;
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  hydratePreferences(emptyPreferenceDocument());
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useCollapsedSections', () => {
  it('restores the panels the user collapsed last time', () => {
    hydrateObjectTypePreferences(OBJECT_TYPE_ID, {
      ...emptyObjectTypePreferences(),
      collapsedSections: [RELATIONS_PANEL_ID],
    });

    const panels = useCollapsedSections(OBJECT_TYPE_ID);

    expect(panels.isOpen(RELATIONS_PANEL_ID)).toBe(false);
    expect(panels.isOpen('group:ungrouped')).toBe(true);
  });

  it('keeps panels collapsed by two separate callers side by side', async () => {
    const served = serveJson(updateRoute, documentWith([RELATIONS_PANEL_ID, 'group:ungrouped']));
    const relations = useCollapsedSections(OBJECT_TYPE_ID);
    const form = useCollapsedSections(OBJECT_TYPE_ID);

    relations.setOpen(RELATIONS_PANEL_ID, false);
    form.setOpen('group:ungrouped', false);

    await useUserPreferences().flush();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/preferences');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.body).toEqual({
      objectTypes: {
        [OBJECT_TYPE_ID]: {
          collapsedSections: [RELATIONS_PANEL_ID, 'group:ungrouped'],
        },
      },
    });
    expect(relations.isOpen(RELATIONS_PANEL_ID)).toBe(false);
    expect(form.isOpen('group:ungrouped')).toBe(false);
  });

  it('still collapses a panel when the policy forbids remembering it', async () => {
    const served = serveJson(updateRoute, emptyPreferenceDocument());
    const document = emptyPreferenceDocument();
    document.policy.panelState = { records: false };
    hydratePreferences(document);

    const panels = useCollapsedSections(OBJECT_TYPE_ID);

    panels.setOpen(RELATIONS_PANEL_ID, false);
    await useUserPreferences().flush();

    expect(panels.isOpen(RELATIONS_PANEL_ID)).toBe(false);
    expect(served.requests).toHaveLength(0);
  });
});
