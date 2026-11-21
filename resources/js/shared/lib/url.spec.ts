import { describe, expect, it } from 'vitest';
import { plainOrigin } from './url';

describe('plainOrigin', () => {
  it('keeps a bare web origin', () => {
    expect(plainOrigin('https://shop.test')).toBe('https://shop.test');
    expect(plainOrigin('http://localhost:8080')).toBe('http://localhost:8080');
  });

  it('refuses an address with a path, another scheme or nothing', () => {
    expect(plainOrigin('https://shop.test/path')).toBeNull();
    expect(plainOrigin('javascript:alert(1)')).toBeNull();
    expect(plainOrigin('null')).toBeNull();
    expect(plainOrigin('')).toBeNull();
    expect(plainOrigin(null)).toBeNull();
    expect(plainOrigin(undefined)).toBeNull();
  });
});
