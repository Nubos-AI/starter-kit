import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { toast } from 'vue-sonner';
import {
  errorReport,
  execute,
  history,
  status,
} from '@/actions/App/Http/Controllers/Import/ImportExecutionsController';
import { serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import type { ServedRoute, ServeOptions } from '@/shared/testing/msw';
import { useFakePollTimers } from '@/shared/testing/pendingTimers';
import { setUrlDefaults } from '@/wayfinder';
import { useJobProgress } from './useJobProgress';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const MAX_CONSECUTIVE_FAILURES = 5;

const OBJECT_TYPE = 'contacts';
const BATCH_ID = '01BATCH00K5N3Q8V9WYE6M2H7C';
const IMPORT_JOB_ID = '01IMPJOB0K5N3Q8V9WYE6M2H7C';
const OTHER_JOB_ID = '01OTHER00K5N3Q8V9WYE6M2H7C';

const PAYLOAD = {
  path: 'imports/contacts.csv',
  format: 'csv',
  sheet: null,
  mapping: { name: 'A', email: 'B' },
  duplicate_mode: 'upsert',
} as const;

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

const executeRoute = execute({ objectType: OBJECT_TYPE });

const statusRoute = status({ objectType: OBJECT_TYPE, batch: BATCH_ID });

const historyRoute = history({ objectType: OBJECT_TYPE });

interface StatusTick {
  progress?: number;
  processedJobs?: number;
  totalJobs?: number;
  failedJobs?: number;
  finished?: boolean;
  cancelled?: boolean;
}

function serveExecute(): ServedRoute {
  return serveJson(executeRoute, { batchId: BATCH_ID, importJobId: IMPORT_JOB_ID }, { status: 202 });
}

function serveStatus(tick: StatusTick, options: ServeOptions = {}): ServedRoute {
  return serveJson(statusRoute, { batchId: BATCH_ID, ...tick }, options);
}

function createProgress(): {
  scope: ReturnType<typeof effectScope>;
  api: ReturnType<typeof useJobProgress>;
} {
  const scope = effectScope();
  let api: ReturnType<typeof useJobProgress> | undefined;

  scope.run(() => {
    api = useJobProgress();
  });

  return { scope, api: api as ReturnType<typeof useJobProgress> };
}

afterEach(() => {
  vi.useRealTimers();
  vi.clearAllMocks();
  vi.restoreAllMocks();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useJobProgress — execute + poll orchestration', () => {
  it('POSTs execute with the payload/XSRF header, stores the batch, then polls status across ticks while unfinished', async () => {
    useFakePollTimers();
    document.cookie = 'XSRF-TOKEN=poll-token';

    const executed = serveExecute();
    const later = serveStatus({
      progress: 50,
      processedJobs: 2,
      totalJobs: 4,
      failedJobs: 0,
      finished: false,
    });
    const first = serveStatus(
      {
        progress: 25,
        processedJobs: 1,
        totalJobs: 4,
        failedJobs: 0,
        finished: false,
      },
      { once: true },
    );

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(api.progress.value).toBe(25));

      expect(executed.requests).toHaveLength(1);
      expect(executed.lastRequest()?.method).toBe('POST');
      expect(executed.lastRequest()?.url.pathname).toBe(`/nubos/engine/import/${OBJECT_TYPE}/execute`);
      expect(executed.lastRequest()?.body).toMatchObject({
        path: PAYLOAD.path,
        format: PAYLOAD.format,
        mapping: PAYLOAD.mapping,
        duplicate_mode: PAYLOAD.duplicate_mode,
      });
      expect(executed.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('poll-token');

      expect(api.status.value).toBe('polling');
      expect(api.active.value).toBe(true);
      expect(api.processedJobs.value).toBe(1);
      expect(api.totalJobs.value).toBe(4);

      expect(first.requests).toHaveLength(1);
      expect(first.lastRequest()?.method).toBe('GET');
      expect(first.lastRequest()?.url.pathname).toBe(`/nubos/engine/import/${OBJECT_TYPE}/status/${BATCH_ID}`);

      await vi.advanceTimersByTimeAsync(1500);
      await vi.waitFor(() => expect(api.progress.value).toBe(50));

      expect(api.processedJobs.value).toBe(2);
      expect(api.status.value).toBe('polling');
      expect(later.requests).toHaveLength(1);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('on terminal WITH errors: GETs history, matches the row by importJobId, exposes counts and a non-null errorReportUrl', async () => {
    useFakePollTimers();

    serveExecute();
    serveStatus({
      progress: 100,
      processedJobs: 4,
      totalJobs: 4,
      failedJobs: 2,
      finished: true,
    });
    const historyServed = serveJson(historyRoute, {
      data: [
        {
          id: OTHER_JOB_ID,
          status: 'completed',
          createdCount: 1,
          updatedCount: 1,
          errorCount: 0,
          hasErrorReport: false,
        },
        {
          id: IMPORT_JOB_ID,
          status: 'completed',
          createdCount: 10,
          updatedCount: 3,
          errorCount: 2,
          hasErrorReport: true,
        },
      ],
    });

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(toast.warning).toHaveBeenCalled());

      expect(historyServed.requests).toHaveLength(1);
      expect(historyServed.lastRequest()?.method).toBe('GET');
      expect(historyServed.lastRequest()?.url.pathname).toBe(`/nubos/engine/import/${OBJECT_TYPE}/history`);

      expect(api.active.value).toBe(false);
      expect(api.status.value).toBe('finished');
      expect(toast.warning).toHaveBeenCalledExactlyOnceWith('Import abgeschlossen — 2 Zeile(n) fehlerhaft.');
      expect(toast.success).not.toHaveBeenCalled();
      expect(api.createdCount.value).toBe(10);
      expect(api.updatedCount.value).toBe(3);
      expect(api.errorCount.value).toBe(2);

      expect(api.errorReportUrl.value).toBe(
        errorReport.url({
          objectType: OBJECT_TYPE,
          importJob: IMPORT_JOB_ID,
        }),
      );
      expect(String(api.errorReportUrl.value)).not.toContain(BATCH_ID);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('on terminal with ZERO errors: hydrates counts, leaves errorReportUrl null and schedules no further polls', async () => {
    useFakePollTimers();

    serveExecute();
    const polled = serveStatus({
      progress: 100,
      processedJobs: 2,
      totalJobs: 2,
      failedJobs: 0,
      finished: true,
    });
    serveJson(historyRoute, {
      data: [
        {
          id: IMPORT_JOB_ID,
          status: 'completed',
          createdCount: 5,
          updatedCount: 0,
          errorCount: 0,
          hasErrorReport: false,
        },
      ],
    });

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(toast.success).toHaveBeenCalled());

      expect(api.active.value).toBe(false);
      expect(api.status.value).toBe('finished');
      expect(toast.success).toHaveBeenCalledExactlyOnceWith('Import erfolgreich abgeschlossen.');
      expect(toast.warning).not.toHaveBeenCalled();
      expect(api.createdCount.value).toBe(5);
      expect(api.updatedCount.value).toBe(0);
      expect(api.errorCount.value).toBe(0);
      expect(api.errorReportUrl.value).toBeNull();
      expect(polled.requests).toHaveLength(1);

      await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

      expect(polled.requests).toHaveLength(1);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('stops after MAX_CONSECUTIVE_FAILURES failing polls (status=failed) and never resumes — no poll storm', async () => {
    useFakePollTimers();

    serveExecute();
    const polled = serveNetworkError(statusRoute);

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });

      for (let attempt = 1; attempt <= MAX_CONSECUTIVE_FAILURES; attempt++) {
        await vi.waitFor(() => expect(polled.requests).toHaveLength(attempt));
        await vi.advanceTimersByTimeAsync(1500);
      }

      await vi.waitFor(() => expect(api.status.value).toBe('failed'));

      expect(polled.requests).toHaveLength(MAX_CONSECUTIVE_FAILURES);
      expect(api.active.value).toBe(false);
      expect(toast.error).toHaveBeenCalledWith('Der Fortschritt des Imports konnte nicht abgerufen werden.');

      await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

      expect(polled.requests).toHaveLength(MAX_CONSECUTIVE_FAILURES);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('keeps polling after a single failed status answer and resumes the progress', async () => {
    useFakePollTimers();

    serveExecute();
    serveStatus({ progress: 60, finished: false });
    const failed = serveServerError(statusRoute, {}, { once: true });

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(failed.requests).toHaveLength(1));

      expect(api.status.value).toBe('polling');

      await vi.advanceTimersByTimeAsync(1500);
      await vi.waitFor(() => expect(api.progress.value).toBe(60));

      expect(api.status.value).toBe('polling');
      expect(toast.error).not.toHaveBeenCalled();
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('onScopeDispose clears the timer so no poll fires after the scope is stopped', async () => {
    useFakePollTimers();

    serveExecute();
    const polled = serveStatus({
      progress: 20,
      processedJobs: 1,
      totalJobs: 5,
      failedJobs: 0,
      finished: false,
    });

    const { scope, api } = createProgress();

    api.start(PAYLOAD, { objectType: OBJECT_TYPE });
    await vi.waitFor(() => expect(api.progress.value).toBe(20));

    expect(polled.requests).toHaveLength(1);

    scope.stop();

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

    expect(polled.requests).toHaveLength(1);
  });
});

describe('useJobProgress — the error branches', () => {
  it('ends a cancelled import as cancelled, says so and reads no history', async () => {
    useFakePollTimers();

    serveExecute();
    serveStatus({ cancelled: true, progress: 30 });
    const historyServed = serveJson(historyRoute, { data: [] });

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(api.status.value).toBe('cancelled'));

      expect(toast.info).toHaveBeenCalledWith('Import abgebrochen.');
      expect(toast.success).not.toHaveBeenCalled();
      expect(historyServed.requests).toHaveLength(0);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('says the import could not be started when execute is refused and never polls', async () => {
    useFakePollTimers();

    serveServerError(executeRoute, {});
    const polled = serveStatus({ finished: false });

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(api.status.value).toBe('failed'));

      expect(toast.error).toHaveBeenCalledWith(
        'Der Import konnte nicht gestartet werden. Bitte versuchen Sie es erneut.',
      );
      expect(polled.requests).toHaveLength(0);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('says the import could not be started when the network drops execute', async () => {
    serveNetworkError(executeRoute);
    const polled = serveStatus({ finished: false });

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() => expect(api.status.value).toBe('failed'));

      expect(api.active.value).toBe(false);
      expect(toast.error).toHaveBeenCalledWith(
        'Der Import konnte nicht gestartet werden. Bitte versuchen Sie es erneut.',
      );
      expect(polled.requests).toHaveLength(0);
    } finally {
      api.stop();
      scope.stop();
    }
  });

  it('still finishes the import but warns that the error list could not be loaded when the history fails', async () => {
    useFakePollTimers();

    serveExecute();
    serveStatus({ finished: true, failedJobs: 0 });
    serveServerError(historyRoute, {});

    const { scope, api } = createProgress();

    try {
      api.start(PAYLOAD, { objectType: OBJECT_TYPE });
      await vi.waitFor(() =>
        expect(toast.warning).toHaveBeenCalledWith('Die Fehlerliste des Imports konnte nicht geladen werden.'),
      );

      expect(api.status.value).toBe('finished');
      expect(api.errorReportUrl.value).toBeNull();
    } finally {
      api.stop();
      scope.stop();
    }
  });
});
