import { describe, expect, it } from 'vitest';
import { bytesPerKilobyte, formatFileSize } from './fileSize';

describe('formatFileSize', () => {
  it('shows a small file as at least one kilobyte', () => {
    expect(formatFileSize(10, 'de')).toBe('1 KB');
    expect(formatFileSize(5 * bytesPerKilobyte, 'de')).toBe('5 KB');
  });

  it('shows a file from one megabyte on in megabytes', () => {
    expect(formatFileSize(1.5 * bytesPerKilobyte * bytesPerKilobyte, 'de')).toBe('1,5 MB');
  });
});
