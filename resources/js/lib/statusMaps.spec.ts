import { describe, expect, it } from 'vitest';
import { IMPORT_JOB_STATUS, resolveStatus } from '@/lib/statusMaps';

describe('statusMaps', () => {
    it('falls back to the raw value with an outline variant for unknown status', () => {
        expect(resolveStatus(IMPORT_JOB_STATUS, 'weird')).toEqual({
            label: 'weird',
            variant: 'outline',
        });
    });
});
