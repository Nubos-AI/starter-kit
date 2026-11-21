import { describe, expect, it } from 'vitest';
import { moduleSources } from '@/tests/moduleSources';
import { missingExemptions } from '@/tests/shippedSources';

const WRITE_METHOD = /'(POST|PUT|PATCH|DELETE)'/;

const SHARED_HELPER = "from '@/shared/lib/errorResponse'";

const EXEMPT: Record<string, string> = {
  '../shared/export/data/useExport.ts': 'POST starts an export job; its own repair pass is open',
  '../domains/business-data/ui-record-field/composables/useFormulaBackfill.ts':
    'POST cancels a backfill run; its own repair pass is open',
  '../domains/integrations/feature-import/data/useImportWizard.ts':
    'POST uploads and previews an import; its own repair pass is open',
  '../domains/integrations/feature-import/composables/useJobProgress.ts':
    'POST executes an import job; its own repair pass is open',
  '../domains/business-data/feature-records/data/useRecordDatasource.ts':
    'POST reads a page of records, it writes nothing',
  '../domains/analytics/feature-reports/data/useReportPreview.ts':
    'POST reads a preview and its refusal carries reason, not message',
  'nubos/documents/resources/js/domains/documents/feature-documents/data/useTemplatePreview.ts':
    'POST reads a preview, it writes nothing',
  'nubos/crm/resources/js/domains/web-engagement/feature-live-chat/data/useTypingSignal.ts':
    'POST is a fire-and-forget typing hint; a lost hint has nothing to show',
  '../domains/analytics/feature-dashboards/data/useWidgetResults.ts': 'POST reads a widget result, it writes nothing',
};

const sources: Record<string, string> = {
  ...(import.meta.glob(
    [
      '../shared/composables/*.ts',
      '../shared/*/data/*.ts',
      '../shared/*/composables/*.ts',
      '../domains/*/*/data/*.ts',
      '../domains/*/*/composables/*.ts',
      '../platform/*/data/*.ts',
      '../platform/*/composables/*.ts',
      '../app-shell/composables/*.ts',
    ],
    {
      eager: true,
      query: '?raw',
      import: 'default',
    },
  ) as Record<string, string>),
  ...Object.fromEntries(
    Object.entries(moduleSources).filter(([path]) =>
      /^nubos\/[^/]+\/resources\/js\/(?:shared\/composables|(?:shared\/[^/]+|domains\/[^/]+\/[^/]+|extensions\/[^/]+)\/(?:data|composables))\/[^/]+\.ts$/.test(
        path,
      ),
    ),
  ),
};

function shippedComposables(): Array<[string, string]> {
  return Object.entries(sources).filter(([path]) => !path.endsWith('.spec.ts'));
}

function writesWithoutTheSharedHelper(): string[] {
  return shippedComposables()
    .filter(([, source]) => WRITE_METHOD.test(source) && !source.includes(SHARED_HELPER))
    .map(([path]) => path);
}

describe('every composable that writes surfaces the reason the server gave', () => {
  it('reads the composables at all', () => {
    expect(shippedComposables().length).toBeGreaterThan(20);
  });

  it('routes every write path through the shared error helper', () => {
    expect(writesWithoutTheSharedHelper().filter((path) => EXEMPT[path] === undefined)).toEqual([]);
  });

  it('names a composable that still exists for every exemption', () => {
    expect(missingExemptions(Object.keys(EXEMPT), sources)).toEqual([]);
  });
});
