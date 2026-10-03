export type RelationDirection = 'outgoing' | 'incoming';

export interface RelationEntry {
    linkId: string;
    recordId: string;
    recordNumber: string | null;
    label: string;
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
    entriesTruncated: boolean;
    candidatesExhausted?: boolean;
    optionsSearch?: string;
    entriesMatched?: number;
}
