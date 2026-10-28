import { describe, expect, it } from 'vitest';

const HARD_CODED_GERMAN_LOCALE = /['"]de-DE['"]/;

const sources = import.meta.glob(
    ['../**/*.{ts,vue}', '../../../packages/*/*/resources/js/**/*.{ts,vue}'],
    { eager: true, query: '?raw', import: 'default' },
) as Record<string, string>;

function isShipped(path: string): boolean {
    return !path.includes('/tests/') && !path.endsWith('.spec.ts');
}

describe('numbers and dates follow the active language', () => {
    it('never pins a formatter to the German locale', () => {
        const offenders = Object.entries(sources)
            .filter(([path]) => isShipped(path))
            .flatMap(([path, source]) =>
                source
                    .split('\n')
                    .map((line, index) => ({ line, no: index + 1 }))
                    .filter((entry) =>
                        HARD_CODED_GERMAN_LOCALE.test(entry.line),
                    )
                    .map((entry) => `${path}:${entry.no}`),
            );

        expect(offenders).toEqual([]);
    });
});
