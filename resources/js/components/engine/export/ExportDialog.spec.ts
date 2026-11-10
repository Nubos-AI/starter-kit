import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';
import ExportDialog from '@/components/engine/export/ExportDialog.vue';
import type * as ExportModule from '@/composables/useExport';
import type { SelectionSnapshot } from '@/composables/useRecordSelection';
import { selectStubs } from '@/tests/selectStubs';
import type { RecordObjectType } from '@/types/records';

const { start } = vi.hoisted(() => ({ start: vi.fn() }));

vi.mock('@/composables/useExport', async (importOriginal) => {
    const actual = await importOriginal<typeof ExportModule>();

    return {
        ...actual,
        useExport: () => ({
            start,
            stop: vi.fn(),
            reset: vi.fn(),
            loadPresets: vi.fn(() => Promise.resolve()),
            savePreset: vi.fn(),
            presets: ref([]),
            status: ref('idle'),
            progress: ref(0),
            processedJobs: ref(0),
            totalJobs: ref(0),
            failedJobs: ref(0),
            downloadUrl: ref(null),
            active: computed(() => false),
        }),
    };
});

const objectType: RecordObjectType = {
    id: 'ot-1',
    key: 'companies',
    slug: 'companies',
    name: 'Companies',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const slot = { template: '<div><slot /></div>' };

function exportCurrentView(snapshot: SelectionSnapshot): unknown {
    start.mockClear();

    const wrapper = mount(ExportDialog, {
        props: {
            open: true,
            objectType,
            fieldDefinitions: [],
            segmentId: snapshot.segmentId,
            snapshotProvider: () => snapshot,
        },
        global: {
            stubs: {
                ...selectStubs,
                Dialog: slot,
                DialogContent: slot,
                DialogHeader: slot,
                DialogTitle: slot,
                DialogDescription: slot,
                DialogFooter: slot,
                FieldSelector: { template: '<div />' },
            },
        },
    });

    void wrapper.find('[data-export-start]').trigger('click');

    return start.mock.calls[0]?.[0];
}

const baseSnapshot: SelectionSnapshot = {
    filterModel: { stage: { type: 'equals', filter: 'open' } },
    sortModel: [],
    search: null,
    segmentId: null,
    drillDown: null,
    matchCount: null,
};

describe('ExportDialog — the current view keeps the list it was opened on', () => {
    it('exports the current view within the segment the grid shows', () => {
        const payload = exportCurrentView({
            ...baseSnapshot,
            segmentId: 'segment-open-deals',
        });

        expect(payload).toMatchObject({
            scope: {
                mode: 'view',
                filterModel: baseSnapshot.filterModel,
                segmentId: 'segment-open-deals',
                drillDown: null,
            },
        });
    });

    it('exports the current view within the report section it was drilled into', () => {
        const drillDown = { report: 'report-1', group: 'north', series: null };

        const payload = exportCurrentView({ ...baseSnapshot, drillDown });

        expect(payload).toMatchObject({
            scope: { mode: 'view', segmentId: null, drillDown },
        });
    });
});
