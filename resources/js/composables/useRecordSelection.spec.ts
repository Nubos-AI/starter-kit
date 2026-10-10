import { describe, expect, it, vi } from 'vitest';
import { useRecordSelection } from '@/composables/useRecordSelection';
import type { SelectionSnapshot } from '@/composables/useRecordSelection';

const emptySnapshot = (): SelectionSnapshot => ({
    filterModel: {},
    sortModel: [],
    search: null,
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
            excludedIds: [],
        });
    });

    it('treats every row as selected except explicitly excluded ones', () => {
        const s = useRecordSelection(emptySnapshot);
        s.selectAllMatching();

        expect(s.isSelected('x')).toBe(true);

        s.toggle('x');
        expect(s.isSelected('x')).toBe(false);
        expect(s.summary.value.label).toContain('1');

        const payload = s.buildSelectionPayload();
        expect(payload).toMatchObject({
            mode: 'all-matching',
            excludedIds: ['x'],
        });
    });
});

describe('useRecordSelection — state retention across view toggle (SC-13)', () => {
    it('keeps the selection intact for the lifetime of the composable instance', () => {
        const s = useRecordSelection(emptySnapshot);
        s.toggle('keep-1');
        s.toggle('keep-2');

        expect([...s.selectedIds.value]).toEqual(['keep-1', 'keep-2']);
        expect(s.isSelected('keep-1')).toBe(true);
    });

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
