export function navPath(url: unknown): string {
    if (typeof url !== 'string') {
        return '';
    }

    const path = url.split(/[?#]/)[0];

    return path.length > 1 && path.endsWith('/') ? path.slice(0, -1) : path;
}

function covers(path: string, target: string): boolean {
    return target !== '' && (path === target || path.startsWith(`${target}/`));
}

export function bestNavHref(path: string, hrefs: string[]): string | null {
    const current = navPath(path);

    return (
        hrefs
            .map(navPath)
            .filter((target) => covers(current, target))
            .sort((a, b) => b.length - a.length)
            .at(0) ?? null
    );
}
