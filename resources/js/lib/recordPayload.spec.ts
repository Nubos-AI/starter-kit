import { describe, expect, it } from 'vitest';
import { extractRecord } from '@/lib/recordPayload';

const record = {
    id: 'rec-1',
    objectTypeId: 'obj-1',
    stageId: null,
    ownerId: null,
    recordNumber: null,
    title: 'Acme GmbH',
    externalReferenceId: null,
    version: 3,
    data: {},
    createdAt: null,
    updatedAt: null,
};

describe('lib/recordPayload', () => {
    it('takes a bare record as it is', () => {
        expect(extractRecord(record)).toBe(record);
    });

    it('unwraps a record the resource wrapped in data', () => {
        expect(extractRecord({ data: record })).toBe(record);
    });
});
