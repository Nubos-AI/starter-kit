import type { SourceLink } from '@/types/links';

export const RECORD_LINK_KEY = 'record';

function isSourceLink(value: unknown): value is SourceLink {
    if (value === null || typeof value !== 'object') {
        return false;
    }

    const candidate = value as Record<string, unknown>;

    return (
        typeof candidate.key === 'string' &&
        typeof candidate.id === 'string' &&
        typeof candidate.label === 'string' &&
        typeof candidate.href === 'string' &&
        candidate.href !== ''
    );
}

export function readSourceLinks(raw: unknown): SourceLink[] {
    return Array.isArray(raw) ? raw.filter(isSourceLink) : [];
}

export function linksBesidesRecord(
    links: SourceLink[],
    recordId: string,
): SourceLink[] {
    return links.filter(
        (link) => !(link.key === RECORD_LINK_KEY && link.id === recordId),
    );
}
