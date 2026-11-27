import { describe, expect, it } from 'vitest';
import type { FieldGrantDraft, FieldPermissionEntry } from '../../../../shared/workspace-access/types/fieldPermissions';
import { buildFieldPermissionDelta } from './fieldPermissionDelta';

function field(overrides: Partial<FieldPermissionEntry> = {}): FieldPermissionEntry {
  return {
    id: 'field-1',
    key: 'name',
    read: true,
    write: true,
    ...overrides,
  };
}

function draftFromServer(
  fields: FieldPermissionEntry[],
  overrides: Record<string, FieldGrantDraft> = {},
): Record<string, FieldGrantDraft> {
  const draft: Record<string, FieldGrantDraft> = {};

  for (const entry of fields) {
    draft[entry.id] = { read: entry.read, write: entry.write };
  }

  return { ...draft, ...overrides };
}

describe('buildFieldPermissionDelta', () => {
  it('sends a restriction that takes seeing away', () => {
    const fields = [field({ id: 'field-1', key: 'email' })];
    const draft = draftFromServer(fields, {
      'field-1': { read: false, write: false },
    });

    const delta = buildFieldPermissionDelta(fields, draft, {
      isEscalated: false,
    });

    expect(delta.payload).toEqual([
      {
        field_definition_id: 'field-1',
        can_read: false,
        can_write: false,
      },
    ]);
  });
});
