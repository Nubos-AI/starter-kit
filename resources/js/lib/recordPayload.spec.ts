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

    it('does not mistake a wrapper for a record just because it has data', () => {
        const wrapper = { data: record, meta: { total: 1 } };

        expect(extractRecord(wrapper)).toBe(record);
    });
});
