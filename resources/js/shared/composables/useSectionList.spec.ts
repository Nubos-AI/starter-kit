import { beforeEach, describe, expect, it } from 'vitest';
import UserPreferencesController from '@/actions/App/Http/Controllers/Preferences/UserPreferencesController';
import { RELATIONS_PANEL_ID } from '@/domains/business-data/feature-records/utils/recordPanels';
import type { SectionListKey } from '@/shared/composables/useSectionList';
import { useSectionList } from '@/shared/composables/useSectionList';
import {
  hydrateObjectTypePreferences,
  hydratePreferences,
  useUserPreferences,
} from '@/shared/composables/useUserPreferences';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveJson } from '@/shared/testing/msw';
import { emptyPreferenceDocument } from '@/shared/testing/preferences';
import { emptyObjectTypePreferences } from '@/shared/types/preferences';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const OBJECT_TYPE_ID = '01JD9K2M4P7QR8XKAV0T3ZC5NE';

const DATA_PANEL_ID = 'group:ungrouped';

const sectionListKeys: SectionListKey[] = ['collapsedSections', 'hiddenSections'];

function serveStoredSections(key: SectionListKey, sections: string[]): ServedRoute {
  const answer = emptyPreferenceDocument();
  answer.objectTypes[OBJECT_TYPE_ID] = {
    ...emptyObjectTypePreferences(),
    [key]: sections,
  };

  return serveJson(UserPreferencesController.update(), answer);
}

beforeEach(() => {
  hydratePreferences(emptyPreferenceDocument());
});

describe('useSectionList', () => {
  it.each(sectionListKeys)('holds no %s entry while nothing is stored', (key) => {
    const list = useSectionList(OBJECT_TYPE_ID, key);

    expect(list.entries.value).toEqual([]);
    expect(list.contains(RELATIONS_PANEL_ID)).toBe(false);
    expect(list.contains(DATA_PANEL_ID)).toBe(false);
  });

  it.each(sectionListKeys)('restores the %s the user stored last time', (key) => {
    const stored = emptyObjectTypePreferences();
    stored[key] = [RELATIONS_PANEL_ID];
    hydrateObjectTypePreferences(OBJECT_TYPE_ID, stored);

    const list = useSectionList(OBJECT_TYPE_ID, key);

    expect(list.entries.value).toEqual([RELATIONS_PANEL_ID]);
    expect(list.contains(RELATIONS_PANEL_ID)).toBe(true);
    expect(list.contains(DATA_PANEL_ID)).toBe(false);
  });

  it.each(sectionListKeys)('drops a panel from the stored %s when it is unset again', async (key) => {
    const served = serveStoredSections(key, []);
    const list = useSectionList(OBJECT_TYPE_ID, key);

    list.set(RELATIONS_PANEL_ID, true);
    list.set(RELATIONS_PANEL_ID, false);

    await useUserPreferences().flush();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.body).toEqual({
      objectTypes: { [OBJECT_TYPE_ID]: { [key]: [] } },
    });
    expect(list.contains(RELATIONS_PANEL_ID)).toBe(false);
  });

  it.each(sectionListKeys)('keeps the %s local and silent without an object type', async (key) => {
    const served = serveStoredSections(key, []);
    const list = useSectionList(null, key);

    list.set(DATA_PANEL_ID, true);
    await useUserPreferences().flush();

    expect(list.contains(DATA_PANEL_ID)).toBe(true);
    expect(served.requests).toHaveLength(0);
  });

  it.each(sectionListKeys)('adds a panel to the stored %s and keeps what the server confirmed', async (key) => {
    const served = serveStoredSections(key, [RELATIONS_PANEL_ID]);
    const list = useSectionList(OBJECT_TYPE_ID, key);

    list.set(RELATIONS_PANEL_ID, true);

    expect(list.contains(RELATIONS_PANEL_ID)).toBe(true);

    await useUserPreferences().flush();

    expect(served.lastRequest()?.body).toEqual({
      objectTypes: {
        [OBJECT_TYPE_ID]: { [key]: [RELATIONS_PANEL_ID] },
      },
    });
    expect(list.entries.value).toEqual([RELATIONS_PANEL_ID]);
  });
});
