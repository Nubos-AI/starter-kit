export interface NavTarget {
    href: string;
    matches?: string[];
}

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

function claims(path: string, claim: string): boolean {
    return claim !== '' && path.startsWith(`${claim}/`);
}

function weightOf(path: string, target: NavTarget): number {
    const own = navPath(target.href);
    const claimed = (target.matches ?? [])
        .map(navPath)
        .filter((claim) => claims(path, claim))
        .map((claim) => claim.length + 1);

    return Math.max(covers(path, own) ? own.length : -1, ...claimed);
}

export function bestNavHref(path: string, targets: NavTarget[]): string | null {
    const current = navPath(path);

    return (
        targets
            .map((target) => ({
                href: navPath(target.href),
                weight: weightOf(current, target),
            }))
            .filter((candidate) => candidate.weight >= 0)
            .sort((a, b) => b.weight - a.weight)
            .at(0)?.href ?? null
    );
}
