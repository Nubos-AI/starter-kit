import { beforeEach, describe, expect, it } from 'vitest';
import { useRecordListMemory } from '@/composables/useRecordListMemory';

const OBJECT_TYPE_ID = 'ot-1';

const SEGMENT_ID = '01SEGMENT00000000000001';

beforeEach(() => {
    sessionStorage.clear();
});

describe('useRecordListMemory', () => {
    it('recalls nothing before the list was ever narrowed', () => {
        expect(useRecordListMemory(OBJECT_TYPE_ID).recall()).toBeNull();
    });

    it('hands back the segment and the search of the last visit', () => {
        const memory = useRecordListMemory(OBJECT_TYPE_ID);

        memory.remember({ segmentId: SEGMENT_ID, search: 'muster' });

        expect(useRecordListMemory(OBJECT_TYPE_ID).recall()).toEqual({
            segmentId: SEGMENT_ID,
            search: 'muster',
        });
    });

    it('keeps every object type on its own memory', () => {
        useRecordListMemory(OBJECT_TYPE_ID).remember({
            segmentId: SEGMENT_ID,
            search: 'muster',
        });

        expect(useRecordListMemory('ot-2').recall()).toBeNull();
    });

    it('forgets the list state once nothing narrows it any more', () => {
        const memory = useRecordListMemory(OBJECT_TYPE_ID);

        memory.remember({ segmentId: SEGMENT_ID, search: 'muster' });
        memory.remember({ segmentId: null, search: '' });

        expect(memory.recall()).toBeNull();
    });

    it('survives a stored value that is no longer readable', () => {
        sessionStorage.setItem(
            `nubos.records.list.${OBJECT_TYPE_ID}`,
            'not json',
        );

        expect(useRecordListMemory(OBJECT_TYPE_ID).recall()).toBeNull();
    });
});
