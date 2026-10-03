import { describe, expect, it } from 'vitest';

const URL_LITERAL = /(?:`|')\/[a-z][a-z0-9-]*\//;

const ALLOWED: Record<string, string> = {
    '/components/teams/TeamSwitcher.vue':
        'builds the segment swap itself — it is what sets the team, not a route target',
};

const sources = import.meta.glob('../**/*.{ts,vue}', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

function isReviewable(path: string): boolean {
    return (
        !path.startsWith('../actions/') &&
        !path.startsWith('../routes/') &&
        !path.startsWith('../wayfinder') &&
        !path.endsWith('.spec.ts')
    );
}

describe('route targets resolve through Wayfinder', () => {
    it('has no hand-written application URL literals', () => {
        const offenders = Object.entries(sources)
            .filter(([path]) => isReviewable(path))
            .map(([path, source]) => ({
                file: path.replace('..', ''),
                hits: source
                    .split('\n')
                    .map((line, index) => ({ line, no: index + 1 }))
                    .filter((entry) => URL_LITERAL.test(entry.line)),
            }))
            .filter(
                (entry) =>
                    entry.hits.length > 0 && ALLOWED[entry.file] === undefined,
            )
            .map((entry) => `${entry.file}:${entry.hits[0].no}`)
            .sort();

        expect(offenders).toEqual([]);
    });
});
