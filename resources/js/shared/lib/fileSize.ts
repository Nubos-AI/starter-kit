import { formatLocalizedNumber } from '@/shared/lib/localizedNumber';

export const bytesPerKilobyte = 1024;
const bytesPerMegabyte = bytesPerKilobyte * bytesPerKilobyte;

export function formatFileSize(bytes: number, locale: string): string {
  if (bytes < bytesPerMegabyte) {
    return `${Math.max(1, Math.round(bytes / bytesPerKilobyte))} KB`;
  }

  const megabytes = formatLocalizedNumber(bytes / bytesPerMegabyte, { maximumFractionDigits: 1 }, locale);

  return `${megabytes} MB`;
}
