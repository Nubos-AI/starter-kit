import type { ObjectTypeFieldRow } from '@/types/formulas';

export interface FieldGroupRow {
    id: string;
    key: string;
    label: string;
    description: string | null;
    position: number;
}

export interface FieldGroupHeaderRow {
    id: string;
    isGroupHeader: true;
    groupId: string | null;
    label: string;
    count: number;
    collapsed: boolean;
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
