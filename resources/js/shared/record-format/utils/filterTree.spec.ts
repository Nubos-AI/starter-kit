import { describe, expect, it } from 'vitest';
import type { FilterGroupNode } from '@/shared/types/filters';
import { hydratableFilterTree, normalizeFilterTree } from './filterTree';

function group(conditions: FilterGroupNode['conditions'] = []): FilterGroupNode {
  return { combinator: 'and', conditions };
}

describe('hydratableFilterTree', () => {
  it('turns every empty shape the server can send into an absent seed', () => {
    expect(hydratableFilterTree([])).toBeUndefined();
    expect(hydratableFilterTree({})).toBeUndefined();
    expect(hydratableFilterTree(null)).toBeUndefined();
    expect(hydratableFilterTree(undefined)).toBeUndefined();
    expect(hydratableFilterTree('')).toBeUndefined();
  });

  it('passes a stored tree through so the builder can hydrate it', () => {
    const stored = group([{ field: 'stage', operator: 'equals', value: 'won' }]);

    expect(hydratableFilterTree(stored)).toEqual(stored);
  });

  it('keeps an empty group as a hydratable seed rather than dropping it', () => {
    expect(hydratableFilterTree(group())).toEqual(group());
  });
});

describe('normalizeFilterTree', () => {
  it('treats a tree without conditions as no filter at all', () => {
    expect(normalizeFilterTree(null)).toBeNull();
    expect(normalizeFilterTree(group())).toBeNull();
    expect(normalizeFilterTree({ combinator: 'or', conditions: [] })).toBeNull();
  });

  it('treats a group whose only child is an empty group as no filter either', () => {
    expect(normalizeFilterTree(group([group()]))).toBeNull();
  });

  it('keeps a tree that carries a condition', () => {
    const filled = group([{ field: 'stage', operator: 'equals', value: 'won' }]);

    expect(normalizeFilterTree(filled)).toEqual(filled);
  });
});
