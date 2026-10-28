export interface RecordObjectType {
    id: string;
    key: string;
    slug: string;
    name: string;
    requiresDeletionReason: boolean;
    hasHierarchy: boolean;
}

export interface ParentCandidate {
    id: string;
    label: string;
}

export interface RecordAging {
    age: number;
    stage: number | null;
    color: string | null;
    ruleName: string | null;
}

export interface RecordPayload {
    id: string;
    objectTypeId: string;
    pipelineId: string | null;
    stageId: string | null;
    ownerId: string | null;
    recordNumber: string | null;
    title: string;
    externalReferenceId: string | null;
    version: number;
    data: Record<string, unknown>;
    createdAt: string | null;
    updatedAt: string | null;
    stageEnteredAt?: string | null;
    mergedIntoRecordId?: string | null;
    mergedAt?: string | null;
    hierarchyDepth?: number;
    aging?: RecordAging;
}
