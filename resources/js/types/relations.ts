export type RelationDirection = 'outgoing' | 'incoming';

export interface RelationEntry {
    linkId: string;
    recordId: string;
    recordNumber: string | null;
    label: string;
}

export interface RelationEntryBlock {
    entries: RelationEntry[];
    total: number;
    isEstimated: boolean;
    hasMore: boolean;
}

export interface RelationCandidate {
    id: string;
    label: string;
}

export interface RelationGroup {
    relationshipTypeId: string;
    direction: RelationDirection;
    isHierarchy: boolean;
    objectTypeName: string;
    roleLabel: string;
    acceptsOne: boolean;
    canEdit: boolean;
    candidates: RelationCandidate[];
    candidatesTruncated: boolean;
    entries: RelationEntry[];
    entriesTotal: number;
    entriesTotalIsEstimated: boolean;
    entriesTruncated: boolean;
    entriesExhausted?: boolean;
    candidatesExhausted?: boolean;
    optionsSearch?: string;
    entriesMatched?: number;
}
