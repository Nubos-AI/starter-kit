import { describe, expect, it } from 'vitest';
import { parseLocalizedNumber } from '@/shared/lib/localizedNumber';

describe('parseLocalizedNumber', () => {
  it('reads an entered amount through the separators of the language it was typed in', () => {
    expect(parseLocalizedNumber('23.123,45', { group: '.', decimal: ',' })).toBe('23123.45');
    expect(parseLocalizedNumber('-12,5', { group: '.', decimal: ',' })).toBe('-12.5');
    expect(parseLocalizedNumber('23,123.45', { group: ',', decimal: '.' })).toBe('23123.45');
  });
});
