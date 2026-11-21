export interface RecordObjectType {
  id: string;
  key: string;
  slug: string;
  name: string;
  label: string;
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

export interface RecordSaveScope {
  fields: string[];
  updates: string[];
}

export interface RecordSectionContext {
  saving: boolean;
  hasChanges: (fields: string[]) => boolean;
  saveSection: (scope: RecordSaveScope) => Promise<boolean>;
  cancelSection: (fields: string[]) => void;
}

export interface RecordPayload {
  id: string;
  objectTypeId: string;
  ownerId: string | null;
  recordNumber: string | null;
  title: string;
  externalReferenceId: string | null;
  version: number;
  data: Record<string, unknown>;
  suppliedKeys?: string[];
  createdAt: string | null;
  updatedAt: string | null;
  mergedIntoRecordId?: string | null;
  mergedAt?: string | null;
  hierarchyDepth?: number;
  aging?: RecordAging;
}
