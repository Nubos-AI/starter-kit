export function readUrlParam<T extends object>(
    params: T,
    key: keyof T,
): string | null {
    const value = params[key];

    return typeof value === 'string' && value.length > 0 ? value : null;
}
