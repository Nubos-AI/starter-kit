import { describe, expect, it } from 'vitest';
import { isShipped, missingExemptions } from '@/tests/shippedSources';

describe('an exemption that names a source that no longer exists', () => {
  it('is reported for a source of the core', () => {
    expect(missingExemptions(['../shared/lib/gone.ts'], {})).toEqual(['../shared/lib/gone.ts']);
  });

  it('is reported for a source of a module whose other sources are readable', () => {
    expect(
      missingExemptions(['nubos/pipelines/resources/js/shared/lib/gone.ts'], {
        'nubos/pipelines/resources/js/shared/lib/kept.ts': '',
      }),
    ).toEqual(['nubos/pipelines/resources/js/shared/lib/gone.ts']);
  });

  it('is not reported for a module that is not readable here, as in an installed application', () => {
    expect(
      missingExemptions(['nubos/pipelines/resources/js/shared/lib/kept.ts'], {
        'nubos/crm/resources/js/shared/lib/other.ts': '',
        '../shared/lib/core.ts': '',
      }),
    ).toEqual([]);
  });

  it('is not reported while the source exists', () => {
    expect(
      missingExemptions(['../shared/lib/kept.ts'], {
        '../shared/lib/kept.ts': '',
      }),
    ).toEqual([]);
  });
});

describe('a shipped source', () => {
  it('is a source of a feature', () => {
    expect(isShipped('../domains/business-data/feature-records/ui/RecordGrid.vue')).toBe(true);
  });

  it('is never a shared test helper', () => {
    expect(isShipped('../shared/testing/selectStubs.ts')).toBe(false);
  });
});
