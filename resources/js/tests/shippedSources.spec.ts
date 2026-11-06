import { describe, expect, it } from 'vitest';
import { missingExemptions } from '@/tests/shippedSources';

describe('an exemption that names a source that no longer exists', () => {
    it('is reported for a source of the core', () => {
        expect(missingExemptions(['../lib/gone.ts'], {})).toEqual([
            '../lib/gone.ts',
        ]);
    });

    it('is reported for a source of a module whose other sources are readable', () => {
        expect(
            missingExemptions(['nubos/pipelines/resources/js/lib/gone.ts'], {
                'nubos/pipelines/resources/js/lib/kept.ts': '',
            }),
        ).toEqual(['nubos/pipelines/resources/js/lib/gone.ts']);
    });

    it('is not reported for a module that is not readable here, as in an installed application', () => {
        expect(
            missingExemptions(['nubos/pipelines/resources/js/lib/kept.ts'], {
                'nubos/crm/resources/js/lib/other.ts': '',
                '../lib/core.ts': '',
            }),
        ).toEqual([]);
    });

    it('is not reported while the source exists', () => {
        expect(
            missingExemptions(['../lib/kept.ts'], { '../lib/kept.ts': '' }),
        ).toEqual([]);
    });
});
