import { describe, expect, it } from 'vitest';
import { UNGROUPED_HEADER_ID } from '@/domains/business-data/ui-record-field/types/fieldGroups';
import type { FieldGroupRow } from '@/domains/business-data/ui-record-field/types/fieldGroups';
import { headerIdOf } from '@/domains/business-data/ui-record-field/utils/fieldGrouping';
import type { FieldDefinition } from '@/shared/types/fields';
import { buildRecordPanels, RELATIONS_PANEL_ID } from './recordPanels';

const fields = [
  { key: 'titel', field_type: 'text_short', label: 'Titel' },
  {
    key: 'umsatz',
    field_type: 'number',
    label: 'Umsatz',
    field_group_id: 'grp-1',
  },
] as unknown as FieldDefinition[];

const groups: FieldGroupRow[] = [
  {
    id: 'grp-1',
    key: 'zahlen',
    label: 'Zahlen',
    description: null,
    position: 1,
  },
];

describe('buildRecordPanels', () => {
  it('lists the relations panel and every field section of the left column', () => {
    expect(buildRecordPanels(fields, groups, true)).toEqual([
      { id: RELATIONS_PANEL_ID, label: 'Beziehungen' },
      { id: UNGROUPED_HEADER_ID, label: 'Daten' },
      { id: headerIdOf('grp-1'), label: 'Zahlen' },
    ]);
  });

  it('leaves the relations panel out when the object type has no relationships', () => {
    expect(buildRecordPanels(fields, groups, false).map((panel) => panel.id)).not.toContain(RELATIONS_PANEL_ID);
  });

  it('falls back to the single data section when no group is defined', () => {
    expect(buildRecordPanels(fields, [], false).map((panel) => panel.id)).toEqual([UNGROUPED_HEADER_ID]);
  });
});
