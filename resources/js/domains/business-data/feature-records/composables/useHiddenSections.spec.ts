import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import UserPreferencesController from '@/actions/App/Http/Controllers/Preferences/UserPreferencesController';
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
import { RELATIONS_PANEL_ID } from '../utils/recordPanels';
import { useHiddenSections } from './useHiddenSections';

setUrlDefaults({ activeTeam: 'nubos' });

const OBJECT_TYPE_ID = '01JD9K2M4P7QR8XKAV0T3ZC5NE';

const DATA_PANEL_ID = 'group:ungrouped';

const updateRoute = UserPreferencesController.update();

function documentWith(sections: string[]): PreferenceDocument {
  const next = emptyPreferenceDocument();
  next.objectTypes[OBJECT_TYPE_ID] = {
    ...emptyObjectTypePreferences(),
    hiddenSections: sections,
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

describe('useHiddenSections', () => {
  it('restores the panels the user switched off last time', () => {
    hydrateObjectTypePreferences(OBJECT_TYPE_ID, {
      ...emptyObjectTypePreferences(),
      hiddenSections: [RELATIONS_PANEL_ID],
    });

    const panels = useHiddenSections(OBJECT_TYPE_ID);

    expect(panels.isVisible(RELATIONS_PANEL_ID)).toBe(false);
    expect(panels.isVisible(DATA_PANEL_ID)).toBe(true);
    expect(panels.hidden.value).toEqual([RELATIONS_PANEL_ID]);
  });

  it('stores every panel the user switches off', async () => {
    const served = serveJson(updateRoute, documentWith([RELATIONS_PANEL_ID, DATA_PANEL_ID]));
    const panels = useHiddenSections(OBJECT_TYPE_ID);

    panels.setVisible(RELATIONS_PANEL_ID, false);
    panels.setVisible(DATA_PANEL_ID, false);

    await useUserPreferences().flush();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/preferences');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.body).toEqual({
      objectTypes: {
        [OBJECT_TYPE_ID]: {
          hiddenSections: [RELATIONS_PANEL_ID, DATA_PANEL_ID],
        },
      },
    });
    expect(panels.hidden.value).toEqual([RELATIONS_PANEL_ID, DATA_PANEL_ID]);
  });

  it('still hides a panel when the policy forbids remembering it', async () => {
    const served = serveJson(updateRoute, emptyPreferenceDocument());
    const document = emptyPreferenceDocument();
    document.policy.panelVisibility = { records: false };
    hydratePreferences(document);

    const panels = useHiddenSections(OBJECT_TYPE_ID);

    panels.setVisible(RELATIONS_PANEL_ID, false);
    await useUserPreferences().flush();

    expect(panels.isVisible(RELATIONS_PANEL_ID)).toBe(false);
    expect(served.requests).toHaveLength(0);
  });

  it('remembers the panels once its own category is on again while the collapse state is off', async () => {
    const served = serveJson(updateRoute, documentWith([RELATIONS_PANEL_ID]));
    const document = emptyPreferenceDocument();
    document.policy.panelState = { records: false };
    hydratePreferences(document);

    const panels = useHiddenSections(OBJECT_TYPE_ID);

    panels.setVisible(RELATIONS_PANEL_ID, false);
    await useUserPreferences().flush();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.body).toEqual({
      objectTypes: {
        [OBJECT_TYPE_ID]: { hiddenSections: [RELATIONS_PANEL_ID] },
      },
    });
  });
});
