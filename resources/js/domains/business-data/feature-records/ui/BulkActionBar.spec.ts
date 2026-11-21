import { fireEvent, render, screen } from '@testing-library/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';
import { show, store } from '@/actions/App/Http/Controllers/Engine/BulkActionsController';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordObjectType } from '../../../../shared/business-data/types/records';
import type { SelectionSummary } from '../../../../shared/records/composables/useRecordSelection';
import BulkActionBar from './BulkActionBar.vue';

setUrlDefaults({ activeTeam: 'nubos' });

vi.mock('@inertiajs/vue3', () => ({
  router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
  usePage: () => pageState,
}));

const { pageState } = vi.hoisted(() => ({
  pageState: {
    props: {
      auth: {
        user: null,
        can: {} as Record<string, boolean>,
        authority: null,
      },
    },
  },
}));

vi.mock('vue-sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
  },
}));

const confirmStub = {
  props: ['open', 'action'],
  emits: ['confirm'],
  template:
    '<div v-if="open" data-bulk-confirm :data-action="action"><button type="button" @click="$emit(\'confirm\')">Bestätigen</button></div>',
};

const objectType: RecordObjectType = {
  id: 'ot-1',
  key: 'companies',
  slug: 'companies',
  name: 'Companies',
  label: 'Firmen',
  requiresDeletionReason: false,
  hasHierarchy: false,
};

interface RecordAbilityFlags {
  create?: boolean;
  update?: boolean;
  delete?: boolean;
  import?: boolean;
  export?: boolean;
  rulesManage?: boolean;
}

function abilities(overrides: RecordAbilityFlags = {}): Record<string, boolean> {
  const flags = {
    create: true,
    update: true,
    delete: true,
    import: true,
    export: true,
    rulesManage: true,
    ...overrides,
  };

  return {
    'companies.create': flags.create,
    'companies.update': flags.update,
    'companies.delete': flags.delete,
    'companies.import': flags.import,
    'companies.export': flags.export,
    'companies.rules.manage': flags.rulesManage,
  };
}

function renderBar(
  recordAbilities: Record<string, boolean>,
  summary: SelectionSummary = {
    mode: 'visible',
    count: 3,
    excludedCount: 0,
  },
) {
  pageState.props.auth.can = recordAbilities;

  return render(BulkActionBar, {
    props: {
      objectType,
      summary,
      fieldDefinitions: [],
      buildSelectionPayload: () => ({
        mode: 'visible' as const,
        includedIds: ['r1'],
      }),
    },
    global: {
      stubs: {
        BulkConfirmDialog: confirmStub,
        Teleport: true,
      },
    },
  });
}

describe('BulkActionBar — every button carries its own right', () => {
  it.each<[string, RecordAbilityFlags, string[], string[]]>([
    [
      'offers every action on living records to a fully permitted user and never a restore',
      {},
      ['Feld setzen', 'CSV-Export', 'Löschen'],
      ['Wiederherstellen'],
    ],
    [
      'drops the delete action without the delete right',
      { delete: false },
      ['Feld setzen', 'CSV-Export'],
      ['Löschen', 'Wiederherstellen'],
    ],
    [
      'drops only the field action without the update right',
      { update: false },
      ['CSV-Export', 'Löschen'],
      ['Feld setzen', 'Wiederherstellen'],
    ],
    [
      'drops the export action without the export right',
      { export: false },
      ['Feld setzen', 'Löschen'],
      ['CSV-Export', 'Wiederherstellen'],
    ],
  ])('%s', (_case, flags, offeredLabels, droppedLabels) => {
    const text = renderBar(abilities(flags)).container.textContent ?? '';

    for (const label of offeredLabels) {
      expect(text).toContain(label);
    }

    for (const label of droppedLabels) {
      expect(text).not.toContain(label);
    }
  });
});

describe('BulkActionBar — how many records the selection covers', () => {
  it.each<[string, SelectionSummary, string]>([
    ['counts the rows picked one by one', { mode: 'visible', count: 3, excludedCount: 0 }, '3 ausgewählt'],
    [
      'shows the match total the server reported for all matches',
      { mode: 'all-matching', count: 41, excludedCount: 1 },
      '41 ausgewählt',
    ],
    [
      'names all matches without a number while the total is unknown',
      { mode: 'all-matching', count: null, excludedCount: 0 },
      'Alle Treffer ausgewählt',
    ],
    [
      'names the exclusions while the total is unknown',
      { mode: 'all-matching', count: null, excludedCount: 2 },
      'Alle Treffer außer 2 ausgewählt',
    ],
  ])('%s', (_case, summary, label) => {
    const { container } = renderBar(abilities(), summary);

    expect(container.querySelector('[data-selection-summary]')?.textContent?.trim()).toBe(label);
  });
});

const DISPATCH_FAILED = 'Die Massenaktion konnte nicht gestartet werden. Bitte versuchen Sie es erneut.';

function storeRoute() {
  return store({ objectType: 'companies' });
}

async function confirmDeletion(): Promise<void> {
  await fireEvent.click(screen.getByRole('button', { name: 'Löschen' }));
  await fireEvent.click(screen.getByRole('button', { name: 'Bestätigen' }));
}

describe('BulkActionBar — dispatching a confirmed bulk action', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=bulk-token';
  });

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    vi.clearAllMocks();
  });

  it('posts the action with the selection and follows the batch until it settles', async () => {
    const dispatched = serveJson(storeRoute(), { batchId: 'batch-1' }, { status: 202 });
    const progress = serveJson(
      show({ batchId: 'batch-1' }),
      {
        batchId: 'batch-1',
        finished: true,
        processedJobs: 1,
        totalJobs: 1,
      },
      { held: true },
    );
    const { emitted } = renderBar(abilities());

    await confirmDeletion();
    await vi.waitFor(() => expect(progress.requests).toHaveLength(1));

    expect(dispatched.requests).toHaveLength(1);
    expect(dispatched.lastRequest()?.method).toBe('POST');
    expect(dispatched.lastRequest()?.url.pathname).toBe('/nubos/engine/records/companies/bulk');
    expect(dispatched.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('bulk-token');
    expect(dispatched.lastRequest()?.body).toEqual({
      action: 'soft-delete',
      selection: { mode: 'visible', includedIds: ['r1'] },
    });
    expect(progress.lastRequest()?.url.pathname).toBe('/nubos/engine/batches/batch-1');
    expect(screen.getByRole('progressbar')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Löschen' }).hasAttribute('disabled')).toBe(true);
    expect(emitted('finished')).toBeUndefined();

    progress.release();

    await vi.waitFor(() => expect(emitted('finished')).toHaveLength(1));
    expect(screen.getByRole('button', { name: 'Löschen' }).hasAttribute('disabled')).toBe(false);
    expect(toast.error).not.toHaveBeenCalled();
  });
});

describe('BulkActionBar — the reason the server gave for refusing a bulk action', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('shows the message the server sent instead of a fixed sentence', async () => {
    serveValidationError(storeRoute(), {
      selection: ['Zu viele Datensätze für eine Massenaktion.'],
    });
    const { emitted } = renderBar(abilities());

    await confirmDeletion();

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith('Zu viele Datensätze für eine Massenaktion.'));
    expect(screen.queryByRole('progressbar')).toBeNull();
    expect(emitted('finished')).toBeUndefined();
  });

  it('keeps the German fallback when the refusal carries no message', async () => {
    serveServerError(storeRoute(), {});
    renderBar(abilities());

    await confirmDeletion();

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith(DISPATCH_FAILED));
    expect(screen.queryByRole('progressbar')).toBeNull();
  });

  it('keeps the German fallback when the request cannot be sent', async () => {
    serveNetworkError(storeRoute());
    renderBar(abilities());

    await confirmDeletion();

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith(DISPATCH_FAILED));
    expect(screen.queryByRole('progressbar')).toBeNull();
  });
});
