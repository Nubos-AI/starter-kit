import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SectionListKey } from '@/composables/useSectionList';
import { useSectionList } from '@/composables/useSectionList';
import {
    hydrateObjectTypePreferences,
    hydratePreferences,
    useUserPreferences,
} from '@/composables/useUserPreferences';
import { RELATIONS_PANEL_ID } from '@/lib/recordPanels';
import { emptyObjectTypePreferences } from '@/types/preferences';
import type { PreferenceDocument } from '@/types/preferences';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const OBJECT_TYPE_ID = '01JD9K2M4P7QR8XKAV0T3ZC5NE';

const DATA_PANEL_ID = 'group:ungrouped';

const sectionListKeys: SectionListKey[] = [
    'collapsedSections',
    'hiddenSections',
];

function emptyDocument(): PreferenceDocument {
    return {
        settings: {
            appearance: 'system',
            density: 'compact',
            pageSize: 100,
            sidebarOpen: true,
            startObjectTypeId: null,
        },
        objectTypes: {},
        grids: {},
        policy: {
            columnsAndSorting: { records: true, configuration: true },
            viewMode: { records: true },
            filterAndSegment: { records: false },
            layoutAndAppearance: { global: true },
            panelState: { records: true },
            panelVisibility: { records: true },
        },
    };
}

let fetchMock: ReturnType<typeof vi.fn>;

function lastBody(): Record<string, unknown> {
    const call = fetchMock.mock.calls.at(-1);

    return JSON.parse((call?.[1] as RequestInit).body as string) as Record<
        string,
        unknown
    >;
}

beforeEach(() => {
    fetchMock = vi.fn().mockImplementation((_url, init: RequestInit) => {
        const patch = JSON.parse(init.body as string) as {
            objectTypes?: Record<string, Record<string, unknown>>;
        };
        const next = emptyDocument();

        for (const [id, slice] of Object.entries(patch.objectTypes ?? {})) {
            next.objectTypes[id] = {
                ...emptyObjectTypePreferences(),
                ...slice,
            };
        }

        return Promise.resolve({
            ok: true,
            json: () => Promise.resolve(next),
        } as unknown as Response);
    });
    vi.stubGlobal('fetch', fetchMock);
    hydratePreferences(emptyDocument());
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useSectionList', () => {
    it.each(sectionListKeys)(
        'holds no %s entry while nothing is stored',
        (key) => {
            const list = useSectionList(OBJECT_TYPE_ID, key);

            expect(list.entries.value).toEqual([]);
            expect(list.contains(RELATIONS_PANEL_ID)).toBe(false);
            expect(list.contains(DATA_PANEL_ID)).toBe(false);
        },
    );

    it.each(sectionListKeys)(
        'restores the %s the user stored last time',
        (key) => {
            const stored = emptyObjectTypePreferences();
            stored[key] = [RELATIONS_PANEL_ID];
            hydrateObjectTypePreferences(OBJECT_TYPE_ID, stored);

            const list = useSectionList(OBJECT_TYPE_ID, key);

            expect(list.entries.value).toEqual([RELATIONS_PANEL_ID]);
            expect(list.contains(RELATIONS_PANEL_ID)).toBe(true);
            expect(list.contains(DATA_PANEL_ID)).toBe(false);
        },
    );

    it.each(sectionListKeys)(
        'drops a panel from the stored %s when it is unset again',
        async (key) => {
            const list = useSectionList(OBJECT_TYPE_ID, key);

            list.set(RELATIONS_PANEL_ID, true);
            list.set(RELATIONS_PANEL_ID, false);

            await useUserPreferences().flush();

            expect(lastBody()).toEqual({
                objectTypes: { [OBJECT_TYPE_ID]: { [key]: [] } },
            });
            expect(list.contains(RELATIONS_PANEL_ID)).toBe(false);
        },
    );

    it.each(sectionListKeys)(
        'keeps the %s local and silent without an object type',
        async (key) => {
            const list = useSectionList(null, key);

            list.set(DATA_PANEL_ID, true);
            await useUserPreferences().flush();

            expect(list.contains(DATA_PANEL_ID)).toBe(true);
            expect(fetchMock).not.toHaveBeenCalled();
        },
    );
});
