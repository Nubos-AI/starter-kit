import type {
    FieldGridRow,
    FieldGroupHeaderRow,
    FieldGroupRow,
} from '@/types/fieldGroups';
import {
    UNGROUPED_HEADER_ID,
    UNGROUPED_LABEL,
    UNGROUPED_SECTION_LABEL,
} from '@/types/fieldGroups';
import type { FieldDefinition } from '@/types/fields';
import type { ObjectTypeFieldRow } from '@/types/formulas';

export function headerIdOf(groupId: string): string {
    return `group:${groupId}`;
}

function header(
    id: string,
    groupId: string | null,
    label: string,
    fields: ObjectTypeFieldRow[],
    collapsed: ReadonlySet<string>,
): FieldGroupHeaderRow {
    return {
        id,
        isGroupHeader: true,
        groupId,
        label,
        count: fields.length,
        collapsed: collapsed.has(id),
    };
}

function section(
    head: FieldGroupHeaderRow,
    fields: ObjectTypeFieldRow[],
): FieldGridRow[] {
    return head.collapsed ? [head] : [head, ...fields];
}

export function buildFieldGridRows(
    fields: ObjectTypeFieldRow[],
    groups: FieldGroupRow[],
    collapsed: ReadonlySet<string> = new Set(),
): FieldGridRow[] {
    if (groups.length === 0) {
        return [...fields];
    }

    const ungrouped = fields.filter((field) => field.field_group_id === null);

    const rows: FieldGridRow[] =
        ungrouped.length === 0
            ? []
            : section(
                  header(
                      UNGROUPED_HEADER_ID,
                      null,
                      UNGROUPED_LABEL,
                      ungrouped,
                      collapsed,
                  ),
                  ungrouped,
              );

    for (const group of [...groups].sort(
        (left, right) => left.position - right.position,
    )) {
        const members = fields.filter(
            (field) => field.field_group_id === group.id,
        );

        rows.push(
            ...section(
                header(
                    headerIdOf(group.id),
                    group.id,
                    group.label,
                    members,
                    collapsed,
                ),
                members,
            ),
        );
    }

    return rows;
}

export interface FieldSection {
    id: string;
    label: string;
    description: string | null;
    fields: FieldDefinition[];
}

export function buildFieldSections(
    fields: FieldDefinition[],
    groups: FieldGroupRow[],
): FieldSection[] {
    if (groups.length === 0) {
        return [
            {
                id: UNGROUPED_HEADER_ID,
                label: UNGROUPED_SECTION_LABEL,
                description: null,
                fields,
            },
        ];
    }

    const ungrouped = fields.filter(
        (field) => (field.field_group_id ?? null) === null,
    );

    const sections: FieldSection[] =
        ungrouped.length === 0
            ? []
            : [
                  {
                      id: UNGROUPED_HEADER_ID,
                      label: UNGROUPED_SECTION_LABEL,
                      description: null,
                      fields: ungrouped,
                  },
              ];

    for (const group of [...groups].sort(
        (left, right) => left.position - right.position,
    )) {
        const members = fields.filter(
            (field) => field.field_group_id === group.id,
        );

        if (members.length > 0) {
            sections.push({
                id: headerIdOf(group.id),
                label: group.label,
                description: group.description,
                fields: members,
            });
        }
    }

    return sections;
}
