import type { RecordPayload } from '@/types/records';

function isRecordPayload(value: unknown): value is RecordPayload {
    return (
        typeof value === 'object' &&
        value !== null &&
        typeof (value as { version?: unknown }).version === 'number'
    );
}

export function extractRecord(body: unknown): RecordPayload {
    if (isRecordPayload(body)) {
        return body;
    }

    return (body as { data: RecordPayload }).data;
}
