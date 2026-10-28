import type {
    AgChartTheme,
    AgChartThemeParams,
    AgChartThemePalette,
} from 'ag-charts-community';

export interface ChartTokens {
    series: string[];
    neutral: string;
    foreground: string;
    background: string;
}

const seriesTokenNames = [
    '--chart-1',
    '--chart-2',
    '--chart-3',
    '--chart-4',
    '--chart-5',
] as const;

const neutralTokenName = '--chart-neutral';
const foregroundTokenName = '--foreground';
const backgroundTokenName = '--card';

export function resolveChartTokens(root: HTMLElement): ChartTokens {
    const styles = getComputedStyle(root);
    const series = seriesTokenNames.map((name) => readToken(styles, name));

    return {
        series: series.every(isPresent) ? series : [],
        neutral: readToken(styles, neutralTokenName),
        foreground: readToken(styles, foregroundTokenName),
        background: readToken(styles, backgroundTokenName),
    };
}

export function buildChartTheme(
    tokens: ChartTokens,
    isDark: boolean,
): AgChartTheme {
    const theme: AgChartTheme = {
        baseTheme: isDark ? 'ag-default-dark' : 'ag-default',
        params: buildParams(tokens),
    };
    const palette = buildPalette(tokens);

    if (palette !== null) {
        theme.palette = palette;
    }

    return theme;
}

function readToken(styles: CSSStyleDeclaration, name: string): string {
    return styles.getPropertyValue(name).trim();
}

function isPresent(value: string): boolean {
    return value !== '';
}

function buildParams(tokens: ChartTokens): AgChartThemeParams {
    const params: AgChartThemeParams = {};

    if (isPresent(tokens.background)) {
        params.backgroundColor = tokens.background;
    }

    if (isPresent(tokens.foreground)) {
        params.foregroundColor = tokens.foreground;
    }

    return params;
}

function buildPalette(tokens: ChartTokens): AgChartThemePalette | null {
    if (tokens.series.length === 0 || !isPresent(tokens.neutral)) {
        return null;
    }

    const colors = [...tokens.series, tokens.neutral];

    return { fills: [...colors], strokes: [...colors] };
}
