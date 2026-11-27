import { buildFieldSections } from '@/domains/business-data/ui-record-field';
import type { FieldGroupRow } from '@/domains/business-data/ui-record-field';
import type { FieldDefinition } from '@/shared/types/fields';

export const RELATIONS_PANEL_ID = 'relations';

const RELATIONS_PANEL_LABEL = 'Beziehungen';

export interface RecordPanel {
  id: string;
  label: string;
}

export function buildRecordPanels(
  fields: FieldDefinition[],
  groups: FieldGroupRow[],
  hasRelationships: boolean,
): RecordPanel[] {
  const relations: RecordPanel[] = hasRelationships ? [{ id: RELATIONS_PANEL_ID, label: RELATIONS_PANEL_LABEL }] : [];

  return [
    ...relations,
    ...buildFieldSections(fields, groups).map((section): RecordPanel => ({
      id: section.id,
      label: section.label,
    })),
  ];
}
