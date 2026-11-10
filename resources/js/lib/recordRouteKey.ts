interface RecordIdentity {
    id: string;
    recordNumber?: string | null;
}

export function recordRouteKey(record: RecordIdentity): string {
    const businessKey = record.recordNumber ?? null;

    return businessKey === null || businessKey === '' ? record.id : businessKey;
}
