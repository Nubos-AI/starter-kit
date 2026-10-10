import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useHiddenSections } from '@/composables/useHiddenSections';
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

describe('useHiddenSections', () => {
    it('shows every panel while nothing is stored', () => {
        const panels = useHiddenSections(OBJECT_TYPE_ID);

        expect(panels.isVisible(RELATIONS_PANEL_ID)).toBe(true);
        expect(panels.hidden.value).toEqual([]);
    });

    it('restores the panels the user switched off last time', () => {
        hydrateObjectTypePreferences(OBJECT_TYPE_ID, {
            ...emptyObjectTypePreferences(),
            hiddenSections: [RELATIONS_PANEL_ID],
        });

        const panels = useHiddenSections(OBJECT_TYPE_ID);

        expect(panels.isVisible(RELATIONS_PANEL_ID)).toBe(false);
        expect(panels.isVisible(DATA_PANEL_ID)).toBe(true);
    });

    it('stores every panel the user switches off', async () => {
        const panels = useHiddenSections(OBJECT_TYPE_ID);

        panels.setVisible(RELATIONS_PANEL_ID, false);
        panels.setVisible(DATA_PANEL_ID, false);

        await useUserPreferences().flush();

        expect(lastBody()).toEqual({
            objectTypes: {
                [OBJECT_TYPE_ID]: {
                    hiddenSections: [RELATIONS_PANEL_ID, DATA_PANEL_ID],
                },
            },
        });
    });

    it('drops a panel from the stored list when it is switched on again', async () => {
        const panels = useHiddenSections(OBJECT_TYPE_ID);

        panels.setVisible(RELATIONS_PANEL_ID, false);
        panels.setVisible(RELATIONS_PANEL_ID, true);

        await useUserPreferences().flush();

        expect(lastBody()).toEqual({
            objectTypes: { [OBJECT_TYPE_ID]: { hiddenSections: [] } },
        });
        expect(panels.isVisible(RELATIONS_PANEL_ID)).toBe(true);
    });

    it('still hides a panel when the policy forbids remembering it', async () => {
        const document = emptyDocument();
        document.policy.panelVisibility = { records: false };
        hydratePreferences(document);

        const panels = useHiddenSections(OBJECT_TYPE_ID);

        panels.setVisible(RELATIONS_PANEL_ID, false);
        await useUserPreferences().flush();

        expect(panels.isVisible(RELATIONS_PANEL_ID)).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('remembers the panels once its own category is on again while the collapse state is off', async () => {
        const document = emptyDocument();
        document.policy.panelState = { records: false };
        hydratePreferences(document);

        const panels = useHiddenSections(OBJECT_TYPE_ID);

        panels.setVisible(RELATIONS_PANEL_ID, false);
        await useUserPreferences().flush();

        expect(lastBody()).toEqual({
            objectTypes: {
                [OBJECT_TYPE_ID]: { hiddenSections: [RELATIONS_PANEL_ID] },
            },
        });
    });

    it('keeps the state local and silent without an object type', async () => {
        const panels = useHiddenSections(null);

        panels.setVisible(RELATIONS_PANEL_ID, false);
        await useUserPreferences().flush();

        expect(panels.isVisible(RELATIONS_PANEL_ID)).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
