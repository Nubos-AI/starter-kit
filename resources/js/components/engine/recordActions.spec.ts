import { describe, expect, it, vi } from 'vitest';
import type { RecordPayload } from '@/types/records';
import type { RowAction } from '@/types/rowAction';
import { setUrlDefaults } from '@/wayfinder';
import { recordActionsColumn } from './recordActions';

setUrlDefaults({ activeTeam: 'nubos' });

const record = { id: 'rec_123' } as RecordPayload;

function actionsOf(
    column: ReturnType<typeof recordActionsColumn>,
): RowAction<RecordPayload>[] {
    return (
        column.cellRendererParams as { actions: RowAction<RecordPayload>[] }
    ).actions;
}

function column(overrides: Partial<Parameters<typeof recordActionsColumn>[0]>) {
    return recordActionsColumn({
        canUpdate: true,
        canDelete: true,
        onDelete: vi.fn(),
        labels: {
            edit: 'Bearbeiten',
            view: 'Ansehen',
            delete: 'Löschen',
            deleteDenied: 'Keine Berechtigung zum Löschen.',
        },
        ...overrides,
    });
}

describe('recordActionsColumn', () => {
    it('offers edit and delete, both enabled, to a fully permitted user', () => {
        const actions = actionsOf(column({}));

        expect(actions.map((action) => action.testId)).toEqual([
            'record-edit',
            'record-delete',
        ]);
        expect(actions[0].href?.(record)).toBe('/nubos/records/rec_123');
        expect(actions[0].label).toBe('Bearbeiten');
        expect(actions[0].variant).toBe('edit');
        expect(actions[0].isDisabled?.(record)).toBe(false);
        expect(actions[1].isDisabled?.(record)).toBe(false);
    });

    it('hands the row to the delete callback', () => {
        const onDelete = vi.fn();

        actionsOf(column({ onDelete })).at(1)?.onClick?.(record);

        expect(onDelete).toHaveBeenCalledWith(record);
    });

    it('renders the delete action disabled with a reason without the permission', () => {
        const action = actionsOf(column({ canDelete: false })).at(1);

        expect(action?.isDisabled?.(record)).toBe(true);
        expect(action?.disabledReason?.(record)).toContain('Berechtigung');
    });

    it('turns the edit action into a blue view action without the update permission', () => {
        const action = actionsOf(column({ canUpdate: false })).at(0);

        expect(action?.testId).toBe('record-view');
        expect(action?.label).toBe('Ansehen');
        expect(action?.variant).toBe('info');
        expect(action?.isDisabled?.(record)).toBe(false);
        expect(action?.href?.(record)).toBe('/nubos/records/rec_123');
    });
});
