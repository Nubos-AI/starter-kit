import { afterEach, describe, expect, it } from 'vitest';
import type { ChartTokens } from '@/lib/charts/theme';
import { buildChartTheme, resolveChartTokens } from '@/lib/charts/theme';

const light: ChartTokens = {
    series: ['#357DE8', '#82B536', '#BF63F3', '#F68909', '#1558BC'],
    neutral: '#8C8F97',
    foreground: '#292A2E',
    background: '#FFFFFF',
};

const dark: ChartTokens = {
    series: ['#4688EC', '#94C748', '#C97CF4', '#FCA700', '#1558BC'],
    neutral: '#7E8188',
    foreground: '#CECFD2',
    background: '#242528',
};

const emptyTokens: ChartTokens = {
    series: [],
    neutral: '',
    foreground: '',
    background: '',
};

function declarationsFor(tokens: ChartTokens): string {
    return [
        ...tokens.series.map(
            (value, index) => `--chart-${index + 1}: ${value};`,
        ),
        `--chart-neutral: ${tokens.neutral};`,
        `--foreground: ${tokens.foreground};`,
        `--card: ${tokens.background};`,
    ].join(' ');
}

function installTokens(css: string): void {
    const style = document.createElement('style');

    style.setAttribute('data-chart-tokens', '');
    style.textContent = css;
    document.head.appendChild(style);
}

function installProjectTokens(): void {
    installTokens(
        `:root { ${declarationsFor(light)} } .dark { ${declarationsFor(dark)} }`,
    );
}

function enableDarkMode(): void {
    document.documentElement.classList.add('dark');
}

function collectStrings(value: unknown): string[] {
    if (typeof value === 'string') {
        return [value];
    }

    if (Array.isArray(value)) {
        return value.flatMap(collectStrings);
    }

    if (value !== null && typeof value === 'object') {
        return Object.values(value as Record<string, unknown>).flatMap(
            collectStrings,
        );
    }

    return [];
}

afterEach(() => {
    document
        .querySelectorAll('style[data-chart-tokens]')
        .forEach((style) => style.remove());
    document.documentElement.classList.remove('dark');
});

describe('resolveChartTokens', () => {
    it('resolves the five light chart tokens, the neutral grey, foreground and card', () => {
        installProjectTokens();

        expect(resolveChartTokens(document.documentElement)).toEqual(light);
    });

    it('resolves the dark token set once the dark class is on the root element', () => {
        installProjectTokens();
        enableDarkMode();

        expect(resolveChartTokens(document.documentElement)).toEqual(dark);
    });

    it('keeps the neutral grey out of the series in both modes', () => {
        installProjectTokens();

        const resolvedLight = resolveChartTokens(document.documentElement);

        enableDarkMode();

        const resolvedDark = resolveChartTokens(document.documentElement);

        expect(resolvedLight.neutral).toBe('#8C8F97');
        expect(resolvedDark.neutral).toBe('#7E8188');
        expect(resolvedLight.series).not.toContain(resolvedLight.neutral);
        expect(resolvedDark.series).not.toContain(resolvedDark.neutral);
    });

    it('reads --chart-1 and never the --color-chart-1 alias', () => {
        installProjectTokens();
        installTokens(':root { --color-chart-1: hsl(0 100% 50%); }');

        const tokens = resolveChartTokens(document.documentElement);

        expect(tokens.series[0]).toBe('#357DE8');
        expect(tokens.series).not.toContain('hsl(0 100% 50%)');
    });

    it('resolves every value instead of forwarding a css variable reference', () => {
        installProjectTokens();

        const values = collectStrings(
            resolveChartTokens(document.documentElement),
        );

        expect(values).toHaveLength(8);
        expect(values.filter((value) => value.includes('var('))).toEqual([]);
    });

    it('drops the whole series when a single chart token is missing', () => {
        const partial: ChartTokens = {
            ...light,
            series: light.series.slice(0, 4),
        };

        installTokens(`:root { ${declarationsFor(partial)} }`);

        const tokens = resolveChartTokens(document.documentElement);

        expect(tokens.series).toEqual([]);
        expect(tokens.neutral).toBe('#8C8F97');
    });

    it('returns an empty series when the document defines no tokens at all', () => {
        const tokens = resolveChartTokens(document.documentElement);

        expect(tokens.series).toEqual([]);
    });
});

describe('buildChartTheme', () => {
    it('bases the light theme on ag-default', () => {
        expect(buildChartTheme(light, false).baseTheme).toBe('ag-default');
    });

    it('bases the dark theme on ag-default-dark instead of only swapping colours', () => {
        expect(buildChartTheme(dark, true).baseTheme).toBe('ag-default-dark');
    });

    it('appends the neutral grey as the sixth palette entry for "Sonstige"', () => {
        const theme = buildChartTheme(light, false);
        const expected = [...light.series, light.neutral];

        expect(theme.palette?.fills).toEqual(expected);
        expect(theme.palette?.strokes).toEqual(expected);
    });

    it('carries the dark palette when the dark tokens go in', () => {
        const theme = buildChartTheme(dark, true);

        expect(theme.palette?.fills).toEqual([...dark.series, dark.neutral]);
    });

    it('bridges card background and foreground through params only', () => {
        const theme = buildChartTheme(light, false);

        expect(theme.params).toEqual({
            backgroundColor: '#FFFFFF',
            foregroundColor: '#292A2E',
        });
    });

    it('emits no overrides block', () => {
        expect(Object.keys(buildChartTheme(light, false))).not.toContain(
            'overrides',
        );
    });

    it('omits the palette entirely when the tokens carry no series', () => {
        const theme = buildChartTheme({ ...light, series: [] }, false);

        expect(Object.keys(theme)).not.toContain('palette');
        expect(theme.baseTheme).toBe('ag-default');
    });

    it('omits the palette entirely when the neutral grey is missing', () => {
        const theme = buildChartTheme({ ...light, neutral: '' }, false);

        expect(Object.keys(theme)).not.toContain('palette');
        expect(theme.baseTheme).toBe('ag-default');
    });

    it('never emits an empty colour string when every token is missing', () => {
        const theme = buildChartTheme(emptyTokens, true);

        expect(collectStrings(theme)).not.toContain('');
        expect(theme.baseTheme).toBe('ag-default-dark');
    });

    it('never forwards an unresolved css variable into the theme', () => {
        const values = collectStrings(buildChartTheme(light, false));

        expect(values).toContain('#357DE8');
        expect(values.filter((value) => value.includes('var('))).toEqual([]);
    });

    it('builds an equal but freshly constructed theme on every call', () => {
        const first = buildChartTheme(light, false);
        const second = buildChartTheme(light, false);

        expect(first).toEqual(second);
        expect(first).not.toBe(second);
        expect(first.palette).not.toBe(second.palette);
    });

    it('carries the dark stylesheet tokens through to the finished theme', () => {
        installProjectTokens();
        enableDarkMode();

        const tokens = resolveChartTokens(document.documentElement);
        const theme = buildChartTheme(tokens, true);

        expect(theme.baseTheme).toBe('ag-default-dark');
        expect(theme.palette?.fills).toEqual([...dark.series, dark.neutral]);
        expect(theme.params).toEqual({
            backgroundColor: '#242528',
            foregroundColor: '#CECFD2',
        });
    });
});
