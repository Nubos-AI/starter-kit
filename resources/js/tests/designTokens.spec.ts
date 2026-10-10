import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { appCss } from '@/tests/appCss';

const TAILWIND_PALETTE_UTILITY =
    /\b(?:bg|text|border|ring|fill|stroke|from|via|to|divide|outline|decoration|shadow|caret|placeholder)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)-\d{2,3}\b/;

const RAW_COLOR_LITERAL =
    /(?:#[0-9a-fA-F]{3,8}\b|\b(?:rgb|rgba|hsl|hsla|oklch|lab|lch)\(\s*[\d.]+)/;

const sources = import.meta.glob('../**/*.{ts,vue}', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

function isShipped(path: string): boolean {
    return (
        !path.startsWith('../actions/') &&
        !path.startsWith('../routes/') &&
        !path.startsWith('../wayfinder') &&
        !path.startsWith('../tests/') &&
        !path.endsWith('.spec.ts')
    );
}

function offenders(pattern: RegExp): string[] {
    return Object.entries(sources)
        .filter(([path]) => isShipped(path))
        .flatMap(([path, source]) =>
            source
                .split('\n')
                .map((line, index) => ({ line, no: index + 1 }))
                .filter((entry) => pattern.test(entry.line))
                .map((entry) => `${path.replace('..', '')}:${entry.no}`),
        );
}

describe('colour reaches the screen through design tokens only', () => {
    it('keeps column presentation out of page and feature components', () => {
        const localStyles = Object.entries(sources)
            .filter(
                ([path]) =>
                    isShipped(path) && path !== '../lib/tableColumns.ts',
            )
            .filter(([, source]) =>
                /\b(?:cellClass|cellStyle|headerClass|headerStyle)\s*:/.test(
                    source,
                ),
            )
            .map(([path]) => path);

        expect(localStyles).toEqual([]);
    });
    it('routes every table through the shared table components', () => {
        const tableOwners = Object.entries(sources)
            .filter(([path]) => isShipped(path))
            .filter(([, source]) => /<(?:table|AgGridVue)\b/.test(source))
            .map(([path]) => path)
            .sort();

        expect(tableOwners).toEqual([
            '../components/data-grid/DataGrid.vue',
            '../components/data-grid/SimpleTable.vue',
        ]);
    });
    it('keeps visual components on shadcn-vue and Reka', () => {
        expect(
            offenders(/from\s+['"]@atlaskit\/(?!pragmatic-drag-and-drop)/),
        ).toEqual([]);
    });
    it('reads shipped sources at all', () => {
        expect(Object.keys(sources).filter(isShipped).length).toBeGreaterThan(
            100,
        );
    });

    it('carries no colour from the Tailwind default palette', () => {
        expect(offenders(TAILWIND_PALETTE_UTILITY)).toEqual([]);
    });

    it('carries no raw colour literal', () => {
        expect(offenders(RAW_COLOR_LITERAL)).toEqual([]);
    });

    it('recognises both forms and leaves token utilities alone', () => {
        expect(TAILWIND_PALETTE_UTILITY.test('class="bg-green-600"')).toBe(
            true,
        );
        expect(TAILWIND_PALETTE_UTILITY.test('class="text-red-500"')).toBe(
            true,
        );
        expect(TAILWIND_PALETTE_UTILITY.test('class="bg-danger-bold"')).toBe(
            false,
        );
        expect(
            TAILWIND_PALETTE_UTILITY.test('class="text-accent-blue-bolder"'),
        ).toBe(false);
        expect(RAW_COLOR_LITERAL.test("color: '#4B5563'")).toBe(true);
        expect(RAW_COLOR_LITERAL.test('hsl(0 0% 45.1%)')).toBe(true);
        expect(RAW_COLOR_LITERAL.test("color: 'var(--ds-bg-brand-bold)'")).toBe(
            false,
        );
    });
});

function themeValues(dark: boolean): Map<string, string> {
    const values = new Map<string, string>();
    const tokenCss = readFileSync(
        resolve(process.cwd(), 'resources/css/tokens.css'),
        'utf8',
    );

    for (const source of [tokenCss, appCss()]) {
        for (const block of source.matchAll(/(:root|\.dark)\s*\{([^}]+)\}/g)) {
            if (block[1] === '.dark' && !dark) {
                continue;
            }

            for (const declaration of block[2].matchAll(
                /(--[\w-]+):\s*([^;]+);/g,
            )) {
                values.set(declaration[1], declaration[2].trim());
            }
        }
    }

    return values;
}

function luminance(name: string, values: Map<string, string>): number {
    let value = `var(${name})`;
    const visited = new Set<string>();

    while (value.startsWith('var(')) {
        const key = value.slice(4, -1);

        if (visited.has(key)) {
            throw new Error(`Circular token: ${key}`);
        }

        visited.add(key);
        const next = values.get(key);

        if (!next) {
            throw new Error(`Missing token: ${key}`);
        }

        value = next;
    }

    if (!/^#[\da-f]{6}$/i.test(value)) {
        throw new Error(`Expected opaque color: ${value}`);
    }

    const channels = [1, 3, 5].map((offset) => {
        const channel = parseInt(value.slice(offset, offset + 2), 16) / 255;

        return channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}

describe.each([false, true])('text contrast with dark mode = %s', (dark) => {
    const values = themeValues(dark);
    const pairs = [
        ['--foreground', '--background'],
        ['--muted-foreground', '--background'],
        ['--card-foreground', '--card'],
        ['--popover-foreground', '--popover'],
        ...['brand', 'success', 'danger', 'warning'].flatMap((role) =>
            ['', '-hovered', '-pressed'].map((state) => [
                role === 'warning'
                    ? '--ds-text-warning-inverse'
                    : '--ds-text-inverse',
                `--ds-bg-${role}-bold${state}`,
            ]),
        ),
        ...(dark
            ? ['', '-hovered', '-pressed'].map((state) => [
                  '--ds-text-success-solid',
                  `--ds-bg-success-solid${state}`,
              ])
            : []),
    ];

    it.each(pairs)('%s is readable on %s', (foreground, background) => {
        const light = luminance(foreground, values);
        const dark = luminance(background, values);
        const contrast =
            (Math.max(light, dark) + 0.05) / (Math.min(light, dark) + 0.05);
        expect(contrast).toBeGreaterThanOrEqual(4.5);
    });
});

describe('create button text', () => {
    function resolvedValue(name: string, values: Map<string, string>): string {
        let value = `var(${name})`;

        while (value.startsWith('var(')) {
            value = values.get(value.slice(4, -1)) ?? '';
        }

        return value.toLowerCase();
    }

    it('is white on the lime background in light mode', () => {
        expect(
            resolvedValue('--ds-text-success-solid', themeValues(false)),
        ).toBe('#ffffff');
    });

    it('stays dark in dark mode', () => {
        const values = themeValues(true);

        expect(resolvedValue('--ds-text-success-solid', values)).toBe(
            resolvedValue('--ds-palette-neutral-1000', values),
        );
    });
});
