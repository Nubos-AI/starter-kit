import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';
import UserPreferencesController from '@/actions/App/Http/Controllers/Preferences/UserPreferencesController';
import { hydratePreferences, useUserPreferences } from '@/shared/composables/useUserPreferences';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveEmpty, serveJson, serveNetworkError, serveValidationError } from '@/shared/testing/msw';
import type { PreferenceDocument } from '@/shared/types/preferences';
import { setUrlDefaults } from '@/wayfinder';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

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

const PERSIST_ERROR = 'Die Einstellung konnte nicht gespeichert werden.';

const route = UserPreferencesController.update();

function preferenceDocument(): PreferenceDocument {
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

let served: ServedRoute;

function respondWith(next: PreferenceDocument): void {
  served = serveJson(route, next);
}

async function passDebounce(): Promise<void> {
  await vi.advanceTimersByTimeAsync(600);
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  respondWith(preferenceDocument());
  hydratePreferences(preferenceDocument());
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.useRealTimers();
  vi.mocked(toast.error).mockClear();
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

    await passDebounce();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.body).toEqual({
      settings: { density: 'comfortable', pageSize: 25 },
    });
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
  });

  it('waits for the debounce before it sends anything', async () => {
    const preferences = useUserPreferences();

    preferences.patch({ settings: { pageSize: 25 } });
    await vi.advanceTimersByTimeAsync(400);

    expect(served.requests).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(200);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
  });

  it('keeps patches to different scopes side by side instead of overwriting', async () => {
    const preferences = useUserPreferences();

    preferences.patch({ settings: { density: 'comfortable' } });
    preferences.patch({ objectTypes: { abc: { viewMode: 'probe_board' } } });
    preferences.patch({ objectTypes: { abc: { probeAxis: 'stage' } } });

    await passDebounce();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(served.lastRequest()?.body).toEqual({
      settings: { density: 'comfortable' },
      objectTypes: { abc: { viewMode: 'probe_board', probeAxis: 'stage' } },
    });
  });

  it('lets the last value for a repeated key win', async () => {
    const preferences = useUserPreferences();

    preferences.patch({ settings: { pageSize: 25 } });
    preferences.patch({ settings: { pageSize: 50 } });

    await passDebounce();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(served.lastRequest()?.body).toEqual({
      settings: { pageSize: 50 },
    });
  });

  it('applies the patch locally before the request resolves', () => {
    const preferences = useUserPreferences();

    preferences.patch({ settings: { density: 'comfortable' } });

    expect(preferences.settings.value.density).toBe('comfortable');
  });

  it('adopts the server document once the request resolves', async () => {
    const preferences = useUserPreferences();
    const filtered = preferenceDocument();
    filtered.settings.density = 'compact';
    respondWith(filtered);

    preferences.patch({ settings: { density: 'comfortable' } });

    expect(preferences.settings.value.density).toBe('comfortable');

    await passDebounce();
    await vi.waitFor(() => expect(preferences.settings.value.density).toBe('compact'));
  });

  it('sends the queued patch immediately when flushed', async () => {
    const preferences = useUserPreferences();

    preferences.patch({ settings: { pageSize: 25 } });
    await preferences.flush();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.url.pathname).toBe(PREFERENCE_URL);
    expect(served.lastRequest()?.body).toEqual({
      settings: { pageSize: 25 },
    });
  });

  it('sends nothing when flushed without a queued patch', async () => {
    await useUserPreferences().flush();

    expect(served.requests).toHaveLength(0);
  });

  it('says the save failed and keeps the local value when the endpoint fails', async () => {
    serveEmpty(route, { status: 500 });
    const preferences = useUserPreferences();

    preferences.patch({ settings: { pageSize: 25 } });
    await preferences.flush();

    expect(toast.error).toHaveBeenCalledWith(PERSIST_ERROR);
    expect(preferences.settings.value.pageSize).toBe(25);
  });

  it('says the save failed and keeps the local value when the request is rejected', async () => {
    serveNetworkError(route);
    const preferences = useUserPreferences();

    preferences.patch({ settings: { pageSize: 25 } });
    await preferences.flush();

    expect(toast.error).toHaveBeenCalledWith(PERSIST_ERROR);
    expect(preferences.settings.value.pageSize).toBe(25);
  });
});

describe('useUserPreferences — a preference the policy forbids', () => {
  const OBJECT_TYPE_ID = '01JD9K2M4P7QR8XKAV0T3ZC5NE';

  function withPolicy(category: 'layoutAndAppearance' | 'filterAndSegment', allowed: boolean): void {
    const seeded = preferenceDocument();
    seeded.policy[category] = category === 'layoutAndAppearance' ? { global: allowed } : { records: allowed };
    hydratePreferences(seeded);
  }

  it('applies it locally but never sends it while the policy forbids it', async () => {
    withPolicy('layoutAndAppearance', false);

    const preferences = useUserPreferences();

    preferences.patch({ settings: { density: 'comfortable' } });

    expect(preferences.settings.value.density).toBe('comfortable');

    await passDebounce();
    await preferences.flush();

    expect(served.requests).toHaveLength(0);
  });

  it('applies it locally and still sends the preference once the policy allows it', async () => {
    withPolicy('layoutAndAppearance', true);

    const preferences = useUserPreferences();

    preferences.patch({ settings: { density: 'comfortable' } });

    expect(preferences.settings.value.density).toBe('comfortable');

    await passDebounce();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(served.requests.map((request) => request.body)).toEqual([{ settings: { density: 'comfortable' } }]);
  });

  it('sends the allowed half of a mixed patch', async () => {
    withPolicy('filterAndSegment', false);

    useUserPreferences().patch({
      objectTypes: {
        [OBJECT_TYPE_ID]: {
          viewMode: 'probe_board',
          lastSegmentId: '01JD9K2M4P7QR8XKAV0T3ZC5NF',
        },
      },
    });
    await passDebounce();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(served.lastRequest()?.body).toEqual({
      objectTypes: { [OBJECT_TYPE_ID]: { viewMode: 'probe_board' } },
    });
  });
});

describe('useUserPreferences — the reason the server gave', () => {
  it('shows the message the server sent instead of the fixed sentence', async () => {
    const preferences = useUserPreferences();

    serveJson(
      route,
      {
        message: 'Die Startseite gibt es nicht mehr.',
        errors: { 'settings.startObjectTypeId': ['egal'] },
      },
      { status: 422 },
    );

    preferences.patch({ settings: { pageSize: 25 } });
    await preferences.flush();

    expect(toast.error).toHaveBeenCalledWith('Die Startseite gibt es nicht mehr.');
  });

  it('keeps the field errors of a refused save until the next save succeeds', async () => {
    const preferences = useUserPreferences();

    serveValidationError(route, {
      'settings.density': ['Die Dichte ist ungültig.'],
    });

    preferences.patch({ settings: { density: 'compact' } });
    await preferences.flush();

    expect(preferences.errors.value).toEqual({
      'settings.density': 'Die Dichte ist ungültig.',
    });

    respondWith(preferenceDocument());
    preferences.patch({ settings: { density: 'comfortable' } });
    await preferences.flush();

    expect(served.requests).toHaveLength(1);
    expect(preferences.errors.value).toEqual({});
  });
});
