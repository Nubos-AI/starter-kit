import { describe, expect, it } from 'vitest';

const WRITE_METHOD = /'(POST|PUT|PATCH|DELETE)'/;

const SHARED_HELPER = "from '@/lib/errorResponse'";

const EXEMPT: Record<string, string> = {
    'useExport.ts': 'POST starts an export job; its own repair pass is open',
    'useFormulaBackfill.ts':
        'POST cancels a backfill run; its own repair pass is open',
    'useImportWizard.ts':
        'POST uploads and previews an import; its own repair pass is open',
    'useJobProgress.ts':
        'POST starts a formula run; its own repair pass is open',
    'useRecordDatasource.ts': 'POST reads a page of records, it writes nothing',
    'useReportPreview.ts':
        'POST reads a preview and its refusal carries reason, not message',
    'useTemplatePreview.ts': 'POST reads a preview, it writes nothing',
    'useWidgetResults.ts': 'POST reads a widget result, it writes nothing',
};

const sources = import.meta.glob(
    [
        '../composables/*.ts',
        '../../../packages/*/*/resources/js/composables/*.ts',
    ],
    {
        eager: true,
        query: '?raw',
        import: 'default',
    },
) as Record<string, string>;

function fileName(path: string): string {
    return path.slice(path.lastIndexOf('/') + 1);
}

function shippedComposables(): Array<[string, string]> {
    return Object.entries(sources)
        .filter(([path]) => !path.endsWith('.spec.ts'))
        .map(([path, source]): [string, string] => [fileName(path), source]);
}

function writesWithoutTheSharedHelper(): string[] {
    return shippedComposables()
        .filter(
            ([, source]) =>
                WRITE_METHOD.test(source) && !source.includes(SHARED_HELPER),
        )
        .map(([name]) => name);
}

describe('every composable that writes surfaces the reason the server gave', () => {
    it('reads the composables at all', () => {
        expect(shippedComposables().length).toBeGreaterThan(20);
    });

    it('routes every write path through the shared error helper', () => {
        expect(
            writesWithoutTheSharedHelper().filter(
                (name) => EXEMPT[name] === undefined,
            ),
        ).toEqual([]);
    });

    it('names a composable that still exists for every exemption', () => {
        const present = new Set(shippedComposables().map(([name]) => name));

        expect(
            Object.keys(EXEMPT).filter((name) => !present.has(name)),
        ).toEqual([]);
    });
});
