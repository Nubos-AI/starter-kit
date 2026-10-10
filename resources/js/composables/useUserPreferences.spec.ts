import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';
import {
    hydratePreferences,
    useUserPreferences,
} from '@/composables/useUserPreferences';
import type { PreferenceDocument } from '@/types/preferences';
import { setUrlDefaults } from '@/wayfinder';

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        message: vi.fn(),
    },
    Toaster: { name: 'ToasterStub', render: () => null },
}));

setUrlDefaults({ activeTeam: 'nubos' });

const PREFERENCE_URL = '/nubos/engine/preferences';

function document(): PreferenceDocument {
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

function respondWith(next: PreferenceDocument): void {
    fetchMock.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve(next),
    } as unknown as Response);
}

function lastBody(): Record<string, unknown> {
    const call = fetchMock.mock.calls.at(-1);

    return JSON.parse((call?.[1] as RequestInit).body as string) as Record<
        string,
        unknown
    >;
}

beforeEach(() => {
    vi.useFakeTimers();
    fetchMock = vi.fn();
    respondWith(document());
    vi.stubGlobal('fetch', fetchMock);
    hydratePreferences(document());
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('useUserPreferences — the single preference client', () => {
    it('exposes the hydrated settings and policy', () => {
        const preferences = useUserPreferences();

        expect(preferences.settings.value.density).toBe('compact');
        expect(preferences.allows('filterAndSegment', 'records')).toBe(false);
        expect(preferences.allows('viewMode', 'records')).toBe(true);
    });

    it('collapses patches fired in quick succession into one request', async () => {
        const preferences = useUserPreferences();

        preferences.patch({ settings: { density: 'comfortable' } });
        preferences.patch({ settings: { pageSize: 25 } });

        await vi.runAllTimersAsync();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(lastBody()).toEqual({
            settings: { density: 'comfortable', pageSize: 25 },
        });
    });

    it('keeps patches to different scopes side by side instead of overwriting', async () => {
        const preferences = useUserPreferences();

        preferences.patch({ settings: { density: 'comfortable' } });
        preferences.patch({ objectTypes: { abc: { viewMode: 'kanban' } } });
        preferences.patch({ objectTypes: { abc: { kanbanAxis: 'stage' } } });

        await vi.runAllTimersAsync();

        expect(lastBody()).toEqual({
            settings: { density: 'comfortable' },
            objectTypes: { abc: { viewMode: 'kanban', kanbanAxis: 'stage' } },
        });
    });

    it('lets the last value for a repeated key win', async () => {
        const preferences = useUserPreferences();

        preferences.patch({ settings: { pageSize: 25 } });
        preferences.patch({ settings: { pageSize: 50 } });

        await vi.runAllTimersAsync();

        expect(lastBody()).toEqual({ settings: { pageSize: 50 } });
    });

    it('applies the patch locally before the request resolves', () => {
        const preferences = useUserPreferences();

        preferences.patch({ settings: { density: 'comfortable' } });

        expect(preferences.settings.value.density).toBe('comfortable');
    });

    it('adopts the server document once the request resolves', async () => {
        const preferences = useUserPreferences();
        const filtered = document();
        filtered.settings.density = 'compact';
        respondWith(filtered);

        preferences.patch({ settings: { density: 'comfortable' } });
        await vi.runAllTimersAsync();

        expect(preferences.settings.value.density).toBe('compact');
    });

    it('sends the queued patch immediately when flushed', async () => {
        const preferences = useUserPreferences();

        preferences.patch({ settings: { pageSize: 25 } });
        await preferences.flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(PREFERENCE_URL);
    });

    it('says the save failed and keeps the local value when the endpoint fails', async () => {
        const preferences = useUserPreferences();
        fetchMock.mockResolvedValue({ ok: false, status: 500 } as Response);

        preferences.patch({ settings: { pageSize: 25 } });
        await vi.runAllTimersAsync();

        expect(toast.error).toHaveBeenCalledWith(
            'Die Einstellung konnte nicht gespeichert werden.',
        );
        expect(preferences.settings.value.pageSize).toBe(25);
    });
});

describe('useUserPreferences — a preference the policy forbids', () => {
    const OBJECT_TYPE_ID = '01JD9K2M4P7QR8XKAV0T3ZC5NE';

    function withPolicy(
        category: 'layoutAndAppearance' | 'filterAndSegment',
        allowed: boolean,
    ): void {
        const seeded = document();
        seeded.policy[category] =
            category === 'layoutAndAppearance'
                ? { global: allowed }
                : { records: allowed };
        hydratePreferences(seeded);
    }

    it('applies it locally but never sends it', async () => {
        withPolicy('layoutAndAppearance', false);

        const preferences = useUserPreferences();

        preferences.patch({ settings: { density: 'comfortable' } });
        await vi.advanceTimersByTimeAsync(600);

        expect(preferences.settings.value.density).toBe('comfortable');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('sends the allowed half of a mixed patch', async () => {
        withPolicy('filterAndSegment', false);

        useUserPreferences().patch({
            objectTypes: {
                [OBJECT_TYPE_ID]: {
                    viewMode: 'kanban',
                    lastSegmentId: '01JD9K2M4P7QR8XKAV0T3ZC5NF',
                },
            },
        });
        await vi.advanceTimersByTimeAsync(600);

        expect(lastBody()).toEqual({
            objectTypes: { [OBJECT_TYPE_ID]: { viewMode: 'kanban' } },
        });
    });

    it('still sends the preference once the policy allows it', async () => {
        withPolicy('layoutAndAppearance', true);

        useUserPreferences().patch({ settings: { density: 'comfortable' } });
        await vi.advanceTimersByTimeAsync(600);

        expect(lastBody()).toEqual({ settings: { density: 'comfortable' } });
    });
});

describe('useUserPreferences — the reason the server gave', () => {
    it('shows the message the server sent instead of the fixed sentence', async () => {
        const preferences = useUserPreferences();

        fetchMock.mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({
                message: 'Die Startseite gibt es nicht mehr.',
                errors: { 'settings.startObjectTypeId': ['egal'] },
            }),
        } as unknown as Response);

        preferences.patch({ settings: { pageSize: 25 } });
        await vi.runAllTimersAsync();

        expect(toast.error).toHaveBeenCalledWith(
            'Die Startseite gibt es nicht mehr.',
        );
    });
});
