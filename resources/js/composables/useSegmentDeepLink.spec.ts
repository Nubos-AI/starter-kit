import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useSegmentDeepLink } from '@/composables/useSegmentDeepLink';
import {
    hydratePreferences,
    useUserPreferences,
} from '@/composables/useUserPreferences';
import type { PreferenceDocument } from '@/types/preferences';
import type { RecordObjectType } from '@/types/records';

function emptyPreferences(): PreferenceDocument {
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
            filterAndSegment: { records: true },
            layoutAndAppearance: { global: true },
            panelState: { records: true },
            panelVisibility: { records: true },
        },
    };
}

function lastPreferenceBody(
    mock: ReturnType<typeof vi.fn>,
): Record<string, unknown> {
    const call = mock.mock.calls.at(-1);

    return JSON.parse((call?.[1] as RequestInit).body as string) as Record<
        string,
        unknown
    >;
}

const { reloadMock, visitMock } = vi.hoisted(() => ({
    reloadMock: vi.fn(),
    visitMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: reloadMock, visit: visitMock },
    usePage: () => ({ url: '/engine/companies', props: {} }),
}));

const BASE_PATH = '/engine/companies';

const objectType: RecordObjectType = {
    id: '01OBJ0K5N3Q8V9WYE6M2H7COMP',
    key: 'company',
    slug: 'companies',
    name: 'Companies',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const SEGMENT_ID = '01J8ZP0K5N3Q8V9WYE6M2H7QRS';
const OTHER_SEGMENT_ID = '01J8ZP0K5N3Q8V9WYE6M2H7ZZZ';
const FS_REF = 'fs_01J8ZP0K5N3Q8V9WYE6M2H7XYZ';

const filterTree: FilterGroupNode = {
    combinator: 'and',
    conditions: [
        { field: 'name', operator: 'eq', value: 'Müller & Söhne' },
        {
            combinator: 'or',
            conditions: [
                { field: 'city', operator: 'eq', value: 'Zürich' },
                {
                    field: 'note',
                    operator: 'contains',
                    value: 'Straße/Ümlaut ✓',
                },
            ],
        },
    ],
};

function setUrl(search: string): void {
    window.history.replaceState(null, '', `${BASE_PATH}${search}`);
}

async function settle(): Promise<void> {
    await nextTick();
    await flushPromises();
}

beforeEach(() => {
    setUrl('');
    reloadMock.mockReset();
    visitMock.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    setUrl('');
});

describe('useSegmentDeepLink — active segment in the shareable URL (SC-10)', () => {
    it('writes ?segment=<ulid> to window.location.search when a segment is applied', async () => {
        const deepLink = useSegmentDeepLink(objectType);

        deepLink.applySegment(SEGMENT_ID);
        await settle();

        expect(window.location.search).toContain(`segment=${SEGMENT_ID}`);
        expect(deepLink.activeSegmentId.value).toBe(SEGMENT_ID);
    });

    it('reads the active segment from a ?segment URL on syncFromUrl', () => {
        setUrl(`?segment=${SEGMENT_ID}`);

        const deepLink = useSegmentDeepLink(objectType);
        deepLink.syncFromUrl();

        expect(deepLink.activeSegmentId.value).toBe(SEGMENT_ID);
        expect(deepLink.activeFilter.value).toBeNull();
        expect(reloadMock).not.toHaveBeenCalled();
    });
});

describe('useSegmentDeepLink — ad-hoc filter round-trips through the URL (unicode-safe codec)', () => {
    it('encodes an applied filter tree into ?filter and decodes it back to an equal tree', async () => {
        const writer = useSegmentDeepLink(objectType);

        writer.applyFilter(filterTree);
        await settle();

        expect(window.location.search).toContain('filter=');
        expect(window.location.search).not.toContain('filter=fs_');
        expect(reloadMock).not.toHaveBeenCalled();

        const reader = useSegmentDeepLink(objectType);
        reader.syncFromUrl();

        expect(reader.activeFilter.value).toEqual(filterTree);
        expect(reader.activeSegmentId.value).toBeNull();
    });
});

describe('useSegmentDeepLink — shared short-id link resolves opaquely (CD-3)', () => {
    it('passes ?filter=fs_<ulid> to router.reload without decoding it client-side', () => {
        setUrl(`?filter=${FS_REF}`);

        const deepLink = useSegmentDeepLink(objectType);
        deepLink.syncFromUrl();

        expect(reloadMock).toHaveBeenCalledTimes(1);

        const [options] = reloadMock.mock.calls[0] as [
            { data?: Record<string, unknown> },
        ];
        expect(options.data?.filter).toBe(FS_REF);

        expect(deepLink.activeFilter.value).toBeNull();
    });
});

describe('useSegmentDeepLink — last-used restore on an empty URL (D-17)', () => {
    it('restores the segment handed over with the page', async () => {
        const deepLink = useSegmentDeepLink(objectType, {
            lastSegmentId: SEGMENT_ID,
            lastFilter: null,
        });
        await deepLink.restoreLastUsed();
        await settle();

        expect(deepLink.activeSegmentId.value).toBe(SEGMENT_ID);
    });

    it('restores the ad-hoc filter handed over with the page', async () => {
        const deepLink = useSegmentDeepLink(objectType, {
            lastSegmentId: null,
            lastFilter: filterTree,
        });
        await deepLink.restoreLastUsed();
        await settle();

        expect(deepLink.activeFilter.value).toEqual(filterTree);
        expect(deepLink.activeSegmentId.value).toBeNull();
    });

    it('restores nothing when the policy left the slice empty', async () => {
        const deepLink = useSegmentDeepLink(objectType);
        await deepLink.restoreLastUsed();
        await settle();

        expect(deepLink.activeSegmentId.value).toBeNull();
        expect(deepLink.activeFilter.value).toBeNull();
    });

    it('does not overwrite an active URL filter when restoring last-used', async () => {
        const seeder = useSegmentDeepLink(objectType);
        seeder.applyFilter(filterTree);
        await settle();

        const deepLink = useSegmentDeepLink(objectType, {
            lastSegmentId: OTHER_SEGMENT_ID,
            lastFilter: null,
        });
        deepLink.syncFromUrl();
        expect(deepLink.activeFilter.value).toEqual(filterTree);

        await deepLink.restoreLastUsed();
        await settle();

        expect(deepLink.activeFilter.value).toEqual(filterTree);
        expect(deepLink.activeSegmentId.value).toBeNull();
    });
});

describe('useSegmentDeepLink — the chosen segment is handed to the preference client', () => {
    it('remembers a segment and forgets the filter alongside it', async () => {
        hydratePreferences(emptyPreferences());
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(emptyPreferences()),
        } as unknown as Response);
        vi.stubGlobal('fetch', fetchMock);

        useSegmentDeepLink(objectType).applySegment(SEGMENT_ID);
        await useUserPreferences().flush();

        expect(lastPreferenceBody(fetchMock)).toEqual({
            objectTypes: {
                [objectType.id]: {
                    lastSegmentId: SEGMENT_ID,
                    lastFilter: null,
                },
            },
        });
    });

    it('clears both remembered values when the selection is cleared', async () => {
        hydratePreferences(emptyPreferences());
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(emptyPreferences()),
        } as unknown as Response);
        vi.stubGlobal('fetch', fetchMock);

        useSegmentDeepLink(objectType).clear();
        await useUserPreferences().flush();

        expect(lastPreferenceBody(fetchMock)).toEqual({
            objectTypes: {
                [objectType.id]: { lastSegmentId: null, lastFilter: null },
            },
        });
    });
});

const DRILL_DOWN_REPORT_ID = '01REPORT0000000000000001';

describe('useSegmentDeepLink — a drill-down link is never overruled by the last used segment', () => {
    it('sets no segment while a report is in the URL', async () => {
        setUrl(`?report=${DRILL_DOWN_REPORT_ID}&group=v%3Awon`);

        const deepLink = useSegmentDeepLink(objectType, {
            lastSegmentId: OTHER_SEGMENT_ID,
            lastFilter: null,
        });
        deepLink.syncFromUrl();
        await deepLink.restoreLastUsed();
        await settle();

        expect(deepLink.activeSegmentId.value).toBeNull();
        expect(deepLink.activeFilter.value).toBeNull();
    });

    it('still restores the last used segment on a URL that carries no report', async () => {
        setUrl('?group=v%3Awon');

        const deepLink = useSegmentDeepLink(objectType, {
            lastSegmentId: OTHER_SEGMENT_ID,
            lastFilter: null,
        });
        deepLink.syncFromUrl();
        await deepLink.restoreLastUsed();
        await settle();

        expect(deepLink.activeSegmentId.value).toBe(OTHER_SEGMENT_ID);
    });
});

describe('useSegmentDeepLink — a segment retires the drill-down parameters', () => {
    it('drops report, group and series from the URL when a segment is applied', async () => {
        setUrl(`?report=${DRILL_DOWN_REPORT_ID}&group=v%3Awon&series=v%3A2026`);

        useSegmentDeepLink(objectType).applySegment(SEGMENT_ID);
        await settle();

        const search = new URLSearchParams(window.location.search);

        expect(search.get('segment')).toBe(SEGMENT_ID);
        expect(search.has('report')).toBe(false);
        expect(search.has('group')).toBe(false);
        expect(search.has('series')).toBe(false);
    });

    it('drops them again when the selection is cleared', async () => {
        setUrl(
            `?report=${DRILL_DOWN_REPORT_ID}&group=v%3Awon&segment=${SEGMENT_ID}`,
        );

        useSegmentDeepLink(objectType).clear();
        await settle();

        expect(window.location.search).toBe('');
    });
});
