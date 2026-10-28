export function activeLocale(): string {
    if (typeof document === 'undefined') {
        return 'de';
    }

    return document.documentElement.lang || 'de';
}
