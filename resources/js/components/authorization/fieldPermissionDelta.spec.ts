import { describe, expect, it } from 'vitest';
import type {
    FieldGrantDraft,
    FieldPermissionEntry,
} from '@/types/fieldPermissions';
import { buildFieldPermissionDelta } from './fieldPermissionDelta';

function field(
    overrides: Partial<FieldPermissionEntry> = {},
): FieldPermissionEntry {
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

    it('sends a lifted restriction so the server can drop it', () => {
        const fields = [field({ id: 'field-1', read: false, write: false })];
        const draft = draftFromServer(fields, {
            'field-1': { read: true, write: true },
        });

        const delta = buildFieldPermissionDelta(fields, draft, {
            isEscalated: false,
        });

        expect(delta.payload).toEqual([
            {
                field_definition_id: 'field-1',
                can_read: true,
                can_write: true,
            },
        ]);
    });

    it('returns an empty delta for an escalated role even when the whole draft deviates', () => {
        const fields = [
            field({ id: 'field-1', key: 'email' }),
            field({ id: 'field-2', key: 'phone', write: false }),
        ];
        const draft = draftFromServer(fields, {
            'field-1': { read: false, write: false },
            'field-2': { read: true, write: true },
        });

        const delta = buildFieldPermissionDelta(fields, draft, {
            isEscalated: true,
        });

        expect(delta.changes).toEqual([]);
        expect(delta.payload).toEqual([]);
    });

    it('sends only the changed field, never the full field list of the object type', () => {
        const fields = Array.from({ length: 10 }, (_unused, index) =>
            field({ id: `field-${index}`, key: `key_${index}` }),
        );
        const draft = draftFromServer(fields, {
            'field-4': { read: true, write: false },
        });

        const delta = buildFieldPermissionDelta(fields, draft, {
            isEscalated: false,
        });

        expect(delta.payload).toHaveLength(1);
        expect(delta.payload[0].field_definition_id).toBe('field-4');
    });

    it('produces no row when a toggle ends up back at the server state', () => {
        const fields = [field({ id: 'field-1', read: true, write: false })];
        const draft = draftFromServer(fields);
        draft['field-1'] = { read: false, write: false };
        draft['field-1'] = { read: true, write: false };

        const delta = buildFieldPermissionDelta(fields, draft, {
            isEscalated: false,
        });

        expect(delta.changes).toEqual([]);
        expect(delta.payload).toEqual([]);
    });

    it('falls back to the server state for a field missing from the draft', () => {
        const fields = [
            field({ id: 'field-1', key: 'email', write: false }),
            field({ id: 'field-2', key: 'phone' }),
        ];
        const draft: Record<string, FieldGrantDraft> = {
            'field-2': { read: true, write: false },
        };

        const delta = buildFieldPermissionDelta(fields, draft, {
            isEscalated: false,
        });

        expect(delta.payload.map((row) => row.field_definition_id)).toEqual([
            'field-2',
        ]);
    });

    it('returns an empty delta for an empty field list', () => {
        const delta = buildFieldPermissionDelta([], {}, { isEscalated: false });

        expect(delta.changes).toEqual([]);
        expect(delta.payload).toEqual([]);
    });

    it('emits the wire shape with snake_case keys carrying the field id, not the field key', () => {
        const fields = [field({ id: 'field-42', key: 'contact_email' })];
        const draft = draftFromServer(fields, {
            'field-42': { read: true, write: false },
        });

        const delta = buildFieldPermissionDelta(fields, draft, {
            isEscalated: false,
        });

        expect(Object.keys(delta.payload[0]).sort()).toEqual([
            'can_read',
            'can_write',
            'field_definition_id',
        ]);
        expect(delta.payload[0].field_definition_id).toBe('field-42');
    });
});
