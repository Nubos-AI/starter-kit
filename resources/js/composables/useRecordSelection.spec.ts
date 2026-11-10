import { describe, expect, it, vi } from 'vitest';
import { useRecordSelection } from '@/composables/useRecordSelection';
import type { SelectionSnapshot } from '@/composables/useRecordSelection';
import type { ReportDrillDownParams } from '@/types/reports';

const emptySnapshot = (): SelectionSnapshot => ({
    filterModel: {},
    sortModel: [],
    search: null,
    segmentId: null,
    drillDown: null,
    matchCount: null,
});

describe('useRecordSelection — single rows (SC-10)', () => {
    it('toggles individual rows in and out of the visible selection', () => {
        const s = useRecordSelection(emptySnapshot);

        s.toggle('a');
        s.toggle('b');

        expect(s.isSelected('a')).toBe(true);
        expect(s.isSelected('b')).toBe(true);
        expect(s.isSelected('c')).toBe(false);
        expect(s.summary.value.count).toBe(2);
        expect(s.hasSelection.value).toBe(true);

        s.toggle('a');
        expect(s.isSelected('a')).toBe(false);
        expect(s.summary.value.count).toBe(1);
    });

    it('builds a visible payload carrying the included ids', () => {
        const s = useRecordSelection(emptySnapshot);
        s.toggle('a');
        s.toggle('b');

        const payload = s.buildSelectionPayload();

        expect(payload).toEqual({ mode: 'visible', includedIds: ['a', 'b'] });
    });
});

describe('useRecordSelection — all N matching (SC-10)', () => {
    it('freezes the grid filter/sort snapshot when escalating to all-matching', () => {
        const snapshot: SelectionSnapshot = {
            filterModel: { stage: { type: 'equals', filter: 'open' } },
            sortModel: [{ colId: 'title', sort: 'asc' }],
            search: 'muster',
            segmentId: 'segment-open-deals',
            drillDown: null,
            matchCount: null,
        };
        const provider = vi.fn(() => snapshot);
        const s = useRecordSelection(provider);

        s.selectAllMatching();

        expect(provider).toHaveBeenCalledOnce();
        expect(s.summary.value.mode).toBe('all-matching');
        expect(s.summary.value.count).toBeNull();
        expect(s.hasSelection.value).toBe(true);
        expect(s.buildSelectionPayload()).toEqual({
            mode: 'all-matching',
            filterModel: snapshot.filterModel,
            sortModel: snapshot.sortModel,
            search: 'muster',
            segmentId: 'segment-open-deals',
            drillDown: null,
            excludedIds: [],
        });
    });

    it('carries the report section the grid was drilled into so the server narrows to it', () => {
        const drillDown: ReportDrillDownParams = {
            report: 'report-1',
            group: 'north',
            series: null,
        };
        const s = useRecordSelection(() => ({
            ...emptySnapshot(),
            drillDown,
        }));

        s.selectAllMatching();

        expect(s.buildSelectionPayload()).toMatchObject({
            mode: 'all-matching',
            segmentId: null,
            drillDown,
        });
    });

    it('keeps the segment frozen at escalation even when the grid moves on', () => {
        let segmentId: string | null = 'segment-a';
        const s = useRecordSelection(() => ({ ...emptySnapshot(), segmentId }));

        s.selectAllMatching();
        segmentId = 'segment-b';

        expect(s.buildSelectionPayload()).toMatchObject({
            segmentId: 'segment-a',
        });
    });

    it('counts the matches the server reported minus the excluded rows', () => {
        const s = useRecordSelection(() => ({
            ...emptySnapshot(),
            matchCount: 12,
        }));

        s.selectAllMatching();
        s.toggle('x');

        expect(s.summary.value).toEqual({
            mode: 'all-matching',
            count: 11,
            excludedCount: 1,
        });
    });

    it('invents no count when the server has not reported the total yet', () => {
        const s = useRecordSelection(emptySnapshot);

        s.selectAllMatching();

        expect(s.summary.value.count).toBeNull();
    });

    it('treats every row as selected except explicitly excluded ones', () => {
        const s = useRecordSelection(emptySnapshot);
        s.selectAllMatching();

        expect(s.isSelected('x')).toBe(true);

        s.toggle('x');
        expect(s.isSelected('x')).toBe(false);
        expect(s.summary.value.excludedCount).toBe(1);

        const payload = s.buildSelectionPayload();
        expect(payload).toMatchObject({
            mode: 'all-matching',
            excludedIds: ['x'],
        });
    });
});

describe('useRecordSelection — state retention across view toggle (SC-13)', () => {
    it('clear() resets mode, selection and snapshot back to the empty visible state', () => {
        const s = useRecordSelection(emptySnapshot);
        s.selectAllMatching();
        s.toggle('excluded');

        s.clear();

        expect(s.mode.value).toBe('visible');
        expect(s.hasSelection.value).toBe(false);
        expect([...s.selectedIds.value]).toEqual([]);
        expect([...s.excludedIds.value]).toEqual([]);
    });
});
