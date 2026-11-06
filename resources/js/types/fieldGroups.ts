import type { GroupHeaderRow } from '@/components/data-grid/GroupHeaderCellRenderer';
import type { ObjectTypeFieldRow } from '@/types/formulas';

export interface FieldGroupRow {
    id: string;
    key: string;
    label: string;
    description: string | null;
    position: number;
}

export interface FieldGroupHeaderRow extends GroupHeaderRow {
    groupId: string | null;
}

export type FieldGridRow = ObjectTypeFieldRow | FieldGroupHeaderRow;

export const UNGROUPED_HEADER_ID = 'group:ungrouped';

export const UNGROUPED_LABEL = 'Ohne Gruppe';

export const UNGROUPED_SECTION_LABEL = 'Daten';

export function isFieldGroupHeaderRow(
    row: FieldGridRow,
): row is FieldGroupHeaderRow {
    return 'isGroupHeader' in row;
}
