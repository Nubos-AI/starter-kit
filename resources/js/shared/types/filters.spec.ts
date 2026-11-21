import { describe, expect, it } from 'vitest';
import { hasFilterConditions, isFilterGroupNode } from './filters';

describe('isFilterGroupNode', () => {
  it('accepts a group with a known combinator and a condition list', () => {
    expect(isFilterGroupNode({ combinator: 'and', conditions: [] })).toBe(true);
    expect(isFilterGroupNode({ combinator: 'or', conditions: [] })).toBe(true);
  });

  it('refuses an unknown combinator, a missing list or an array', () => {
    expect(isFilterGroupNode({ combinator: 'xor', conditions: [] })).toBe(false);
    expect(isFilterGroupNode({ combinator: 'and' })).toBe(false);
    expect(isFilterGroupNode([])).toBe(false);
    expect(isFilterGroupNode(null)).toBe(false);
  });
});

describe('hasFilterConditions', () => {
  it('accepts any object that carries a condition list', () => {
    expect(hasFilterConditions({ conditions: [] })).toBe(true);
  });

  it('refuses an object without a condition list', () => {
    expect(hasFilterConditions({ conditions: 'x' })).toBe(false);
    expect(hasFilterConditions([])).toBe(false);
  });
});
