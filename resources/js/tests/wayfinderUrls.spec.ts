import { describe, expect, it } from 'vitest';
import { isShipped, sources } from '@/tests/shippedSources';

const URL_LITERAL = /(?:`|')\/[a-z][a-z0-9-]*\//;

const ALLOWED: Record<string, string> = {
    '/components/teams/TeamSwitcher.vue':
        'builds the segment swap itself — it is what sets the team, not a route target',
};

describe('route targets resolve through Wayfinder', () => {
    it('has no hand-written application URL literals', () => {
        expect(Object.keys(sources).length).toBeGreaterThan(100);

        const offenders = Object.entries(sources)
            .filter(([path]) => isShipped(path))
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
