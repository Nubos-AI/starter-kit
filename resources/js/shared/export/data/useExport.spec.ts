import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { toast } from 'vue-sonner';
import {
  index as presetIndex,
  store as presetStore,
} from '@/actions/App/Http/Controllers/Export/ExportFieldPresetsController';
import { download, status, store } from '@/actions/App/Http/Controllers/Export/ExportsController';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import type { ServedRoute } from '@/shared/testing/msw';
import { useFakePollTimers } from '@/shared/testing/pendingTimers';
import { setUrlDefaults } from '@/wayfinder';
import { useExport } from './useExport';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const MAX_CONSECUTIVE_FAILURES = 5;

const OBJECT_TYPE = 'contacts';
const BATCH_ID = '01BATCH00K5N3Q8V9WYE6M2H7C';
const EXPORT_JOB_ID = '01EXPJOB0K5N3Q8V9WYE6M2H7C';

const FILTER_MODEL = {
  status: { filterType: 'text', type: 'equals', filter: 'active' },
} as const;

const SORT_MODEL = [{ colId: 'name', sort: 'asc' }] as const;

const FIELDS = ['name', 'email'] as const;

const VIEW_PAYLOAD = {
  format: 'csv',
  scope: {
    mode: 'view',
    filterModel: FILTER_MODEL,
    sortModel: SORT_MODEL,
  },
  fields: FIELDS,
} as const;

const toastErrorMock = vi.fn();

vi.mock('vue-sonner', () => ({
  toast: {
    success: vi.fn(),
    error: (...args: unknown[]) => toastErrorMock(...args),
    info: vi.fn(),
    warning: vi.fn(),
    message: vi.fn(),
  },
  Toaster: { name: 'ToasterStub', render: () => null },
}));

const storeRoute = store({ objectType: OBJECT_TYPE });

const statusRoute = status({ objectType: OBJECT_TYPE, batch: BATCH_ID });

const presetsRoute = presetIndex({ objectType: OBJECT_TYPE });

const presetStoreRoute = presetStore({ objectType: OBJECT_TYPE });

interface StatusTick {
  progress?: number;
  processedJobs?: number;
  totalJobs?: number;
  failedJobs?: number;
  finished?: boolean;
  cancelled?: boolean;
}

function serveStore(): ServedRoute {
  return serveJson(storeRoute, { batchId: BATCH_ID, exportJobId: EXPORT_JOB_ID }, { status: 202 });
}

function serveStatus(tick: StatusTick): ServedRoute {
  return serveJson(statusRoute, { batchId: BATCH_ID, ...tick });
}

function createExport(): {
  scope: ReturnType<typeof effectScope>;
  api: ReturnType<typeof useExport>;
} {
  const scope = effectScope();
  let api: ReturnType<typeof useExport> | undefined;

  scope.run(() => {
    api = useExport();
  });

  return { scope, api: api as ReturnType<typeof useExport> };
}

afterEach(() => {
  vi.useRealTimers();
  vi.clearAllMocks();
  vi.restoreAllMocks();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useExport — start + poll + download orchestration', () => {
  it('WYSIWYG: start with scope.mode=view POSTs the exact filterModel + sortModel snapshot supplied', async () => {
    useFakePollTimers();
    document.cookie = 'XSRF-TOKEN=export-token';

    const stored = serveStore();
    serveStatus({
      progress: 20,
      processedJobs: 1,
      totalJobs: 5,
      failedJobs: 0,
      finished: false,
    });

    const { scope, api } = createExport();

    try {
      api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(stored.requests).toHaveLength(1));

      const call = stored.lastRequest();
      expect(call?.url.pathname).toBe(`/nubos/engine/export/${OBJECT_TYPE}`);
      expect(call?.method).toBe('POST');
      expect(call?.headers.get('X-XSRF-TOKEN')).toBe('export-token');
      expect(call?.headers.get('Content-Type')).toBe('application/json');

      const body = call?.body as Record<string, unknown>;
      expect(body.format).toBe('csv');
      expect(body.fields).toEqual([...FIELDS]);
      expect(body.scope).toEqual({
        mode: 'view',
        filterModel: FILTER_MODEL,
        sortModel: [...SORT_MODEL],
      });

      expect(api.status.value).toBe('polling');
      expect(api.active.value).toBe(true);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('start POSTs the export store endpoint and consumes the 202 {batchId, exportJobId}, then polls status for that batch', async () => {
    useFakePollTimers();

    const stored = serveStore();
    const polled = serveStatus({
      progress: 40,
      processedJobs: 2,
      totalJobs: 5,
      failedJobs: 0,
      finished: false,
    });

    const { scope, api } = createExport();

    try {
      api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(api.progress.value).toBe(40));

      expect(stored.requests).toHaveLength(1);
      expect(polled.requests).toHaveLength(1);
      expect(polled.lastRequest()?.method).toBe('GET');
      expect(polled.lastRequest()?.url.pathname).toBe(`/nubos/engine/export/${OBJECT_TYPE}/status/${BATCH_ID}`);

      expect(api.processedJobs.value).toBe(2);
      expect(api.totalJobs.value).toBe(5);
      expect(api.status.value).toBe('polling');

      await vi.advanceTimersByTimeAsync(1500);
      await vi.waitFor(() => expect(polled.requests).toHaveLength(2));
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('on terminal finished===true exposes downloadUrl keyed by the CAPTURED exportJobId, not any status-response field', async () => {
    useFakePollTimers();

    serveStore();
    serveStatus({
      progress: 100,
      processedJobs: 5,
      totalJobs: 5,
      failedJobs: 0,
      finished: true,
    });

    const { scope, api } = createExport();

    try {
      api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(api.status.value).toBe('finished'));

      expect(api.active.value).toBe(false);
      expect(api.downloadUrl.value).toBe(
        download.url({
          objectType: OBJECT_TYPE,
          exportJob: EXPORT_JOB_ID,
        }),
      );
      expect(api.downloadUrl.value).toBe(`/nubos/engine/export/${OBJECT_TYPE}/download/${EXPORT_JOB_ID}`);
      expect(String(api.downloadUrl.value)).not.toContain(BATCH_ID);
      expect(toast.success).toHaveBeenCalledWith('Export erfolgreich abgeschlossen.');
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('stops after MAX_CONSECUTIVE_FAILURES failing polls (status=failed) and never resumes — no poll storm', async () => {
    useFakePollTimers();

    serveStore();
    const polled = serveNetworkError(statusRoute);

    const { scope, api } = createExport();

    try {
      api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });

      for (let attempt = 1; attempt <= MAX_CONSECUTIVE_FAILURES; attempt++) {
        await vi.waitFor(() => expect(polled.requests).toHaveLength(attempt));
        await vi.advanceTimersByTimeAsync(1500);
      }

      await vi.waitFor(() => expect(api.status.value).toBe('failed'));

      expect(polled.requests).toHaveLength(MAX_CONSECUTIVE_FAILURES);
      expect(api.active.value).toBe(false);
      expect(api.downloadUrl.value).toBeNull();
      expect(toastErrorMock).toHaveBeenCalledWith('Der Fortschritt des Exports konnte nicht abgerufen werden.');

      await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

      expect(polled.requests).toHaveLength(MAX_CONSECUTIVE_FAILURES);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('counts a status answer outside 2xx as a failed poll until the cap ends the export', async () => {
    useFakePollTimers();

    serveStore();
    const polled = serveServerError(statusRoute);

    const { scope, api } = createExport();

    try {
      api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(polled.requests).toHaveLength(1));

      expect(api.status.value).toBe('polling');
      expect(toastErrorMock).not.toHaveBeenCalled();

      for (let attempt = 2; attempt <= MAX_CONSECUTIVE_FAILURES; attempt++) {
        await vi.advanceTimersByTimeAsync(1500);
        await vi.waitFor(() => expect(polled.requests).toHaveLength(attempt));
      }

      await vi.waitFor(() => expect(api.status.value).toBe('failed'));
      expect(toastErrorMock).toHaveBeenCalledWith('Der Fortschritt des Exports konnte nicht abgerufen werden.');
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('non-ok store response sets a German error state (status=failed, toast.error) and does NOT throw', async () => {
    useFakePollTimers();

    serveServerError(storeRoute, {});
    const polled = serveStatus({ finished: false });

    const { scope, api } = createExport();

    try {
      expect(() => api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE })).not.toThrow();
      await vi.waitFor(() => expect(api.status.value).toBe('failed'));

      expect(api.active.value).toBe(false);
      expect(api.downloadUrl.value).toBeNull();
      expect(polled.requests).toHaveLength(0);

      expect(toastErrorMock).toHaveBeenCalledTimes(1);
      expect(toastErrorMock).toHaveBeenCalledWith(
        'Der Export konnte nicht gestartet werden. Bitte versuchen Sie es erneut.',
      );
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('ends as failed with the German start error when the network drops the start', async () => {
    serveNetworkError(storeRoute);
    const polled = serveStatus({ finished: false });

    const { scope, api } = createExport();

    try {
      api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(api.status.value).toBe('failed'));

      expect(polled.requests).toHaveLength(0);
      expect(toastErrorMock).toHaveBeenCalledWith(
        'Der Export konnte nicht gestartet werden. Bitte versuchen Sie es erneut.',
      );
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('loadPresets GETs the preset index and populates presets from the {data:[]} envelope', async () => {
    const preset = {
      id: '01PRESET00K5N3Q8V9WYE6M2H7',
      name: 'Nur Kontaktdaten',
      fields: ['name', 'email'],
    };

    const served = serveJson(presetsRoute, { data: [preset] });

    const { scope, api } = createExport();

    try {
      await api.loadPresets(OBJECT_TYPE);

      expect(served.requests).toHaveLength(1);
      expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/export/${OBJECT_TYPE}/presets`);
      expect(served.lastRequest()?.method).toBe('GET');
      expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');

      expect(api.presets.value).toEqual([preset]);
    } finally {
      scope.stop();
    }
  });

  it('savePreset POSTs {name, fields} to the preset store endpoint and appends the returned preset', async () => {
    document.cookie = 'XSRF-TOKEN=export-token';

    const created = {
      id: '01PRESET10K5N3Q8V9WYE6M2H7',
      name: 'Complete',
      fields: ['name', 'email', 'phone'],
    };

    const served = serveJson(presetStoreRoute, { data: created }, { status: 201 });

    const { scope, api } = createExport();

    try {
      const result = await api.savePreset(OBJECT_TYPE, 'Complete', ['name', 'email', 'phone']);

      expect(served.requests).toHaveLength(1);
      expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/export/${OBJECT_TYPE}/presets`);
      expect(served.lastRequest()?.method).toBe('POST');
      expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('export-token');
      expect(served.lastRequest()?.body).toEqual({
        name: 'Complete',
        fields: ['name', 'email', 'phone'],
      });

      expect(result).toEqual(created);
      expect(api.presets.value).toContainEqual(created);
      expect(toast.success).toHaveBeenCalledWith('Feld-Preset gespeichert.');
    } finally {
      scope.stop();
    }
  });

  it('onScopeDispose clears the timer so no poll fires after the scope is stopped', async () => {
    useFakePollTimers();

    serveStore();
    const polled = serveStatus({
      progress: 20,
      processedJobs: 1,
      totalJobs: 5,
      failedJobs: 0,
      finished: false,
    });

    const { scope, api } = createExport();

    api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
    await vi.waitFor(() => expect(api.progress.value).toBe(20));

    expect(polled.requests).toHaveLength(1);

    scope.stop();

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

    expect(polled.requests).toHaveLength(1);
  });
});

describe('useExport — the error branches', () => {
  it('ends a cancelled batch as cancelled, says so and offers no download', async () => {
    useFakePollTimers();

    serveStore();
    serveStatus({ cancelled: true, progress: 40 });

    const { scope, api } = createExport();

    try {
      api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(api.status.value).toBe('cancelled'));

      expect(api.active.value).toBe(false);
      expect(api.downloadUrl.value).toBeNull();
      expect(toast.info).toHaveBeenCalledWith('Export abgebrochen.');
      expect(toast.success).not.toHaveBeenCalled();
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('keeps the presets it had and says so when the preset list cannot be loaded', async () => {
    serveServerError(presetsRoute, {});

    const { scope, api } = createExport();

    try {
      await api.loadPresets(OBJECT_TYPE);

      expect(api.presets.value).toEqual([]);
      expect(toastErrorMock).toHaveBeenCalledWith('Die Feld-Presets konnten nicht geladen werden.');
    } finally {
      scope.stop();
    }
  });

  it('says the preset list could not be loaded when the network drops the request', async () => {
    serveNetworkError(presetsRoute);

    const { scope, api } = createExport();

    try {
      await api.loadPresets(OBJECT_TYPE);

      expect(api.presets.value).toEqual([]);
      expect(toastErrorMock).toHaveBeenCalledWith('Die Feld-Presets konnten nicht geladen werden.');
    } finally {
      scope.stop();
    }
  });

  it('answers a refused preset save with null, keeps the list and says the preset was not saved', async () => {
    serveValidationError(presetStoreRoute, {
      name: ['Der Name ist bereits vergeben.'],
    });

    const { scope, api } = createExport();

    try {
      const saved = await api.savePreset(OBJECT_TYPE, 'Neu', FIELDS);

      expect(saved).toBeNull();
      expect(api.presets.value).toEqual([]);
      expect(toastErrorMock).toHaveBeenCalledWith('Das Feld-Preset konnte nicht gespeichert werden.');
      expect(toast.success).not.toHaveBeenCalled();
    } finally {
      scope.stop();
    }
  });

  it('answers a preset save the network dropped with null and says the preset was not saved', async () => {
    serveNetworkError(presetStoreRoute);

    const { scope, api } = createExport();

    try {
      const saved = await api.savePreset(OBJECT_TYPE, 'Neu', FIELDS);

      expect(saved).toBeNull();
      expect(api.presets.value).toEqual([]);
      expect(toastErrorMock).toHaveBeenCalledWith('Das Feld-Preset konnte nicht gespeichert werden.');
    } finally {
      scope.stop();
    }
  });
});
