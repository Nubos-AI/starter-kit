export function plainOrigin(value: string | null | undefined): string | null {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  try {
    const url = new URL(value);
    const isWeb = url.protocol === 'https:' || url.protocol === 'http:';

    return isWeb && url.origin === value ? url.origin : null;
  } catch {
    return null;
  }
}
