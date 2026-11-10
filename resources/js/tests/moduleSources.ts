const DEVELOPMENT_LOCATION = '../../../packages/';

function byModulePath<T>(files: Record<string, T>): Record<string, T> {
    return Object.fromEntries(
        Object.entries(files).map(([path, file]) => [
            path.slice(DEVELOPMENT_LOCATION.length),
            file,
        ]),
    );
}

export const moduleSources = byModulePath(
    import.meta.glob('../../../packages/nubos/*/resources/js/**/*.{ts,vue}', {
        eager: true,
        query: '?raw',
        import: 'default',
    }) as Record<string, string>,
);
