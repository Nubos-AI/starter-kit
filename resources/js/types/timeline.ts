import type { RecordFile } from '@/types/attachments';

export type TimelineActorType = 'user' | 'automation' | 'system';

export type TimelineChannel = 'web' | 'api' | 'automation' | 'system';

export type TimelinePayload = Record<string, unknown> | null;

export interface TimelineEntry {
    id: string;
    sourceKey: string;
    sourceId: string | null;
    actorId: string | null;
    actorType: TimelineActorType | null;
    actorLabel: string | null;
    channel: TimelineChannel | null;
    occurredAt: string;
    canOpenAutomation: boolean;
    payload: TimelinePayload;
    file?: RecordFile | null;
}

export interface TimelineFilterState {
    occurredFrom: string | null;
    occurredTo: string | null;
    actorType: TimelineActorType | null;
    actorId: string | null;
}

export interface TimelineStageFacet {
    id?: string;
    key?: string;
    labels?: Record<string, string>;
}

export interface FieldChangePayload {
    fieldKey: string | null;
    oldValue: unknown;
    newValue: unknown;
    oldLabel: string | null;
    newLabel: string | null;
    automationId: string | null;
}

export interface StageChangePayload {
    oldStage: TimelineStageFacet | null;
    newStage: TimelineStageFacet | null;
    automationId: string | null;
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

function stageFacet(value: unknown): TimelineStageFacet | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const facet: TimelineStageFacet = {};

    if ('id' in value) {
        facet.id = text(value.id) ?? undefined;
    }

    if ('key' in value) {
        facet.key = text(value.key) ?? undefined;
    }

    if (
        'labels' in value &&
        typeof value.labels === 'object' &&
        value.labels !== null
    ) {
        const labels: Record<string, string> = {};

        for (const [locale, label] of Object.entries(value.labels)) {
            const readable = text(label);

            if (readable !== null) {
                labels[locale] = readable;
            }
        }

        facet.labels = labels;
    }

    return facet;
}

export function readFieldChangePayload(
    payload: TimelinePayload,
): FieldChangePayload {
    const source = fields(payload);

    return {
        fieldKey: text(source.field_key),
        oldValue: source.old_value ?? null,
        newValue: source.new_value ?? null,
        oldLabel: text(source.old_label),
        newLabel: text(source.new_label),
        automationId: text(source.automation_id),
    };
}

export function readStageChangePayload(
    payload: TimelinePayload,
): StageChangePayload {
    const source = fields(payload);

    return {
        oldStage: stageFacet(source.old_stage),
        newStage: stageFacet(source.new_stage),
        automationId: text(source.automation_id),
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

export function readAttachmentPayload(
    payload: TimelinePayload,
): AttachmentPayload {
    const source = fields(payload);

    return {
        fileName: text(source.fileName),
        templateName: text(source.templateName),
    };
}
