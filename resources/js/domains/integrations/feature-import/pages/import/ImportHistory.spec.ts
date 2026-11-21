import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { gridColumns, rowActions } from '@/shared/testing/dataGrid';
import ImportHistory from './ImportHistory.vue';

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
  usePage: () => ({
    url: '/nubos/engine/import/companies/history',
    props: {},
  }),
}));

interface HistoryRow {
  id: string;
  status: 'completed';
  originalFilename: string;
  format: string;
  totalRows: number;
  createdCount: number;
  updatedCount: number;
  errorCount: number;
  hasErrorReport: boolean;
  startedAt: string | null;
  finishedAt: string | null;
  createdAt: string | null;
}

type Wrapper = ReturnType<typeof mount>;

const mounted: Wrapper[] = [];

function job(overrides: Partial<HistoryRow> = {}): HistoryRow {
  return {
    id: '01IMPORTJOB000000000000001',
    status: 'completed',
    originalFilename: 'kunden.csv',
    format: 'csv',
    totalRows: 4,
    createdCount: 2,
    updatedCount: 1,
    errorCount: 1,
    hasErrorReport: true,
    startedAt: null,
    finishedAt: null,
    createdAt: '2026-11-01T08:00:00.000Z',
    ...overrides,
  };
}

function mountHistory(jobs: HistoryRow[]): Wrapper {
  const wrapper = mount(ImportHistory, {
    props: { objectType: 'companies', jobs },
  });

  mounted.push(wrapper);

  return wrapper;
}

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('import history', () => {
  it('reads the camel case fields of the import job resource', () => {
    const wrapper = mountHistory([job()]);

    const fields = gridColumns(wrapper).map((column) => column.field);

    expect(fields).toContain('createdAt');
    expect(fields).toContain('originalFilename');
    expect(fields).toContain('totalRows');
  });

  it('offers the error report only for a run that wrote one', () => {
    const wrapper = mountHistory([job()]);
    const action = rowActions<HistoryRow>(wrapper).at(0);

    expect(action?.isVisible?.(job())).toBe(true);
    expect(action?.isVisible?.(job({ hasErrorReport: false }))).toBe(false);
  });
});
