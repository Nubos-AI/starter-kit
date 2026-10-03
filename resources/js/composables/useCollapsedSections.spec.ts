import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useCollapsedSections } from '@/composables/useCollapsedSections';
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

describe('useCollapsedSections', () => {
    it('reports every panel open while nothing is stored', () => {
        const panels = useCollapsedSections(OBJECT_TYPE_ID);

        expect(panels.isOpen(RELATIONS_PANEL_ID)).toBe(true);
        expect(panels.isOpen('group:ungrouped')).toBe(true);
    });

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
        const relations = useCollapsedSections(OBJECT_TYPE_ID);
        const form = useCollapsedSections(OBJECT_TYPE_ID);

        relations.setOpen(RELATIONS_PANEL_ID, false);
        form.setOpen('group:ungrouped', false);

        await useUserPreferences().flush();

        expect(lastBody()).toEqual({
            objectTypes: {
                [OBJECT_TYPE_ID]: {
                    collapsedSections: [RELATIONS_PANEL_ID, 'group:ungrouped'],
                },
            },
        });
        expect(relations.isOpen(RELATIONS_PANEL_ID)).toBe(false);
        expect(form.isOpen('group:ungrouped')).toBe(false);
    });

    it('drops a panel from the stored list when it is opened again', async () => {
        const panels = useCollapsedSections(OBJECT_TYPE_ID);

        panels.setOpen(RELATIONS_PANEL_ID, false);
        panels.setOpen(RELATIONS_PANEL_ID, true);

        await useUserPreferences().flush();

        expect(lastBody()).toEqual({
            objectTypes: { [OBJECT_TYPE_ID]: { collapsedSections: [] } },
        });
        expect(panels.isOpen(RELATIONS_PANEL_ID)).toBe(true);
    });

    it('still collapses a panel when the policy forbids remembering it', async () => {
        const document = emptyDocument();
        document.policy.panelState = { records: false };
        hydratePreferences(document);

        const panels = useCollapsedSections(OBJECT_TYPE_ID);

        panels.setOpen(RELATIONS_PANEL_ID, false);
        await useUserPreferences().flush();

        expect(panels.isOpen(RELATIONS_PANEL_ID)).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('keeps the state local and silent without an object type', async () => {
        const panels = useCollapsedSections(null);

        panels.setOpen('group:ungrouped', false);
        await useUserPreferences().flush();

        expect(panels.isOpen('group:ungrouped')).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
