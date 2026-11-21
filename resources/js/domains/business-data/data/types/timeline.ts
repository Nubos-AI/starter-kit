import type { RecordFile } from './attachments';

export type TimelineActorType = 'user' | 'automation' | 'system';

export type TimelineChannel = 'web' | 'api' | 'automation' | 'system';

export type TimelinePayload = Record<string, unknown> | null;

export interface TimelineEntryFields {
  id: string;
  sourceKey: string;
  sourceId: string | null;
  actorId: string | null;
  actorType: TimelineActorType | null;
  actorLabel: string | null;
  channel: TimelineChannel | null;
  apiTokenLabel: string | null;
  occurredAt: string;
  payload: TimelinePayload;
  file?: RecordFile | null;
}

export interface TimelineEntry extends TimelineEntryFields {
  [moduleAttribute: string]: unknown;
}

export interface TimelineFilterState {
  occurredFrom: string | null;
  occurredTo: string | null;
  actorType: TimelineActorType | null;
  actorId: string | null;
}

export interface FieldChangePayload {
  fieldKey: string | null;
  oldValue: unknown;
  newValue: unknown;
  oldLabel: string | null;
  newLabel: string | null;
}

export interface NotePayload {
  authorName: string | null;
  body: string | null;
}

export interface ReminderPayload {
  state: string | null;
  subject: string | null;
  dueAt: string | null;
  overdue: boolean;
}

export interface RelationPayload {
  action: 'linked' | 'unlinked' | null;
  relationshipName: string | null;
  counterpartId: string | null;
  counterpartNumber: string | null;
}

export interface AttachmentPayload {
  fileName: string | null;
  templateName: string | null;
}

function fields(payload: TimelinePayload): Record<string, unknown> {
  return payload ?? {};
}

function text(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}

export function readFieldChangePayload(payload: TimelinePayload): FieldChangePayload {
  const source = fields(payload);

  return {
    fieldKey: text(source.field_key),
    oldValue: source.old_value ?? null,
    newValue: source.new_value ?? null,
    oldLabel: text(source.old_label),
    newLabel: text(source.new_label),
  };
}

export function readNotePayload(payload: TimelinePayload): NotePayload {
  const source = fields(payload);

  return {
    authorName: text(source.authorName),
    body: text(source.body),
  };
}

export function readReminderPayload(payload: TimelinePayload): ReminderPayload {
  const source = fields(payload);

  return {
    state: text(source.state),
    subject: text(source.subject),
    dueAt: text(source.dueAt),
    overdue: source.overdue === true,
  };
}

export function readRelationPayload(payload: TimelinePayload): RelationPayload {
  const source = fields(payload);
  const action = text(source.action);

  return {
    action: action === 'linked' || action === 'unlinked' ? action : null,
    relationshipName: text(source.relationship_name),
    counterpartId: text(source.counterpart_id),
    counterpartNumber: text(source.counterpart_number),
  };
}

export function readAttachmentPayload(payload: TimelinePayload): AttachmentPayload {
  const source = fields(payload);

  return {
    fileName: text(source.fileName),
    templateName: text(source.templateName),
  };
}
