export interface TrashRow {
    id: string;
    objectTypeName: string;
    objectTypeSlug: string;
    recordNumber: string | null;
    title: string;
    deletionReason: string | null;
    deletedAt: string | null;
    purgeOn: string | null;
    canRestore: boolean;
    canDelete: boolean;
}
