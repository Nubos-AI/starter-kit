import { describe, expect, it } from 'vitest';
import { drillDownToken, isCollectorToken } from './drillDown';

describe('drillDownToken', () => {
  it('prefixes a genuine group value so it stays readable as a value', () => {
    expect(drillDownToken('won', false)).toBe('v:won');
    expect(drillDownToken('', false)).toBe('v:');
  });

  it('keeps a value that carries colons of its own intact', () => {
    expect(drillDownToken('2026-08-01T00:00:00+02:00', false)).toBe('v:2026-08-01T00:00:00+02:00');
    expect(drillDownToken('a:b:c', false)).toBe('v:a:b:c');
  });

  it('gives a group without any value a token of its own', () => {
    expect(drillDownToken(null, false)).toBe('null');
  });

  it('gives the collected bucket a token of its own, whatever it carries', () => {
    expect(drillDownToken(null, true)).toBe('other');
    expect(drillDownToken('won', true)).toBe('other');
  });

  it('never lets a genuine value collide with one of the two markers', () => {
    expect(drillDownToken('null', false)).toBe('v:null');
    expect(drillDownToken('other', false)).toBe('v:other');
    expect(drillDownToken('null', false)).not.toBe(drillDownToken(null, false));
    expect(drillDownToken('other', false)).not.toBe(drillDownToken('other', true));
  });
});

describe('isCollectorToken', () => {
  it('recognises the collected bucket', () => {
    expect(isCollectorToken('other')).toBe(true);
  });

  it('recognises nothing else as collected', () => {
    expect(isCollectorToken('v:other')).toBe(false);
    expect(isCollectorToken('null')).toBe(false);
    expect(isCollectorToken('v:null')).toBe(false);
    expect(isCollectorToken('v:won')).toBe(false);
    expect(isCollectorToken('')).toBe(false);
  });
});
