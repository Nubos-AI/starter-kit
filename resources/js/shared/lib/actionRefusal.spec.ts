import { describe, expect, it } from 'vitest';
import { resolveActionRefusal } from './actionRefusal';

const catalogue = {
  messages: { not_owner: 'refusal.not_owner' },
  fallback: 'refusal.fallback',
};
const t = (key: string): string => `translated:${key}`;

describe('resolveActionRefusal', () => {
  it('answers no text while the action is allowed', () => {
    expect(resolveActionRefusal(null, catalogue, t)).toBeUndefined();
  });

  it('translates a known reason', () => {
    expect(resolveActionRefusal('not_owner', catalogue, t)).toBe('translated:refusal.not_owner');
  });

  it('falls back for an unknown reason', () => {
    expect(resolveActionRefusal('brand_new', catalogue, t)).toBe('translated:refusal.fallback');
  });
});
