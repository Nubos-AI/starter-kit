import { moduleSources } from '@/tests/moduleSources';

export const sources: Record<string, string> = {
    ...(import.meta.glob('../**/*.{ts,vue}', {
        eager: true,
        query: '?raw',
        import: 'default',
    }) as Record<string, string>),
    ...moduleSources,
};

function isOfUnreadableModule(
    path: string,
    present: Record<string, string>,
): boolean {
    const module = path.match(/^nubos\/[^/]+\//)?.[0];

    return (
        module !== undefined &&
        !Object.keys(present).some((source) => source.startsWith(module))
    );
}

export function missingExemptions(
    exempt: string[],
    present: Record<string, string> = sources,
): string[] {
    return exempt.filter(
        (path) =>
            present[path] === undefined && !isOfUnreadableModule(path, present),
    );
}

export function isShipped(path: string): boolean {
    return (
        !path.startsWith('../actions/') &&
        !path.startsWith('../routes/') &&
        !path.startsWith('../wayfinder') &&
        !path.includes('/tests/') &&
        !path.endsWith('.spec.ts')
    );
}
