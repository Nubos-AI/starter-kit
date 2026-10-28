import { describe, expect, it } from 'vitest';
import { useListSelection } from './useListSelection';

describe('useListSelection', () => {
    it('toggles ids on and off and tracks the count', () => {
        const selection = useListSelection();

        selection.toggle('a');
        selection.toggle('b');

        expect(selection.isSelected('a')).toBe(true);
        expect(selection.count.value).toBe(2);
        expect(selection.ids.value).toEqual(['a', 'b']);

        selection.toggle('a');

        expect(selection.isSelected('a')).toBe(false);
        expect(selection.count.value).toBe(1);
    });

    it('clears all selected ids', () => {
        const selection = useListSelection();
        selection.toggle('a');
        selection.toggle('b');

        selection.clear();

        expect(selection.count.value).toBe(0);
        expect(selection.ids.value).toEqual([]);
    });

    it('reports the header state across the visible rows', () => {
        const selection = useListSelection();
        const rows = ['a', 'b', 'c'];

        expect(selection.headerState(rows)).toBe(false);

        selection.toggle('a');
        expect(selection.headerState(rows)).toBe('indeterminate');

        selection.toggle('b');
        selection.toggle('c');
        expect(selection.headerState(rows)).toBe(true);

        expect(selection.headerState([])).toBe(false);
    });

    it('selects all visible rows, then clears them when all are already selected', () => {
        const selection = useListSelection();
        const rows = ['a', 'b'];

        selection.toggleAll(rows);
        expect(selection.count.value).toBe(2);

        selection.toggleAll(rows);
        expect(selection.count.value).toBe(0);
    });

    it('drops stale ids that are no longer visible when pruning', () => {
        const selection = useListSelection();
        selection.toggle('a');
        selection.toggle('gone');

        selection.prune(['a', 'b']);

        expect(selection.isSelected('a')).toBe(true);
        expect(selection.isSelected('gone')).toBe(false);
        expect(selection.count.value).toBe(1);
    });
});
