import type {
    FieldGrantDraft,
    FieldPermissionChange,
    FieldPermissionDelta,
    FieldPermissionDeltaOptions,
    FieldPermissionEntry,
} from '@/types/fieldPermissions';

export function buildFieldPermissionDelta(
    fields: FieldPermissionEntry[],
    draft: Record<string, FieldGrantDraft>,
    options: FieldPermissionDeltaOptions,
): FieldPermissionDelta {
    const changes: FieldPermissionChange[] = [];

    if (!options.isEscalated) {
        for (const field of fields) {
            const grant = draft[field.id] ?? {
                read: field.read,
                write: field.write,
            };

            if (grant.read === field.read && grant.write === field.write) {
                continue;
            }

            changes.push({
                fieldDefinitionId: field.id,
                fieldKey: field.key,
                canRead: grant.read,
                canWrite: grant.write,
            });
        }
    }

    return {
        changes,
        payload: changes.map((change) => ({
            field_definition_id: change.fieldDefinitionId,
            can_read: change.canRead,
            can_write: change.canWrite,
        })),
    };
}
