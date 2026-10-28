import { mount } from '@vue/test-utils';
import type {
    AgChartOptions,
    AgChartTheme,
    DownloadOptions,
} from 'ag-charts-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PropType } from 'vue';
import {
    createSSRApp,
    defineComponent,
    h,
    isReactive,
    nextTick,
    watch,
} from 'vue';
import { renderToString } from 'vue/server-renderer';
import ChartCanvas from '@/components/charts/ChartCanvas.vue';

const chart = vi.hoisted(() => ({
    received: [] as Record<string, unknown>[],
    registerChartModules: vi.fn(),
    download: vi.fn(),
}));

vi.mock('ag-charts-vue3', () => ({
    AgCharts: defineComponent({
        name: 'AgChartsStub',
        props: {
            options: {
                type: Object as PropType<Record<string, unknown>>,
                required: true,
            },
        },
        setup(props) {
            watch(
                () => props.options,
                (options) => {
                    chart.received.push(options);
                },
                { immediate: true },
            );

            return () => h('div', { 'data-chart-stub': true });
        },
        data() {
            return { chart: { download: chart.download } };
        },
    }),
}));

vi.mock('@/lib/charts/moduleRegistry', () => ({
    registerChartModules: chart.registerChartModules,
}));

const lightFill = '#357DE8';
const darkFill = '#4688EC';

function barOptions(): AgChartOptions {
    return {
        data: [{ label: 'A', value: 1 }],
        series: [{ type: 'bar', xKey: 'label', yKey: 'value' }],
    };
}

function lineOptions(): AgChartOptions {
    return {
        data: [{ label: 'A', value: 2 }],
        series: [{ type: 'line', xKey: 'label', yKey: 'value' }],
    };
}

function decoratedOptions(): AgChartOptions {
    return {
        data: [{ label: 'A', value: 1 }],
        series: [{ type: 'bar', xKey: 'label', yKey: 'value' }],
        legend: { position: 'bottom' },
        tooltip: { enabled: true },
    };
}

function axisOptions(): AgChartOptions {
    return {
        data: [{ label: 'A', value: 1 }],
        series: [{ type: 'bar', xKey: 'label', yKey: 'value' }],
        axes: { x: { type: 'category' }, y: { type: 'number' } },
    };
}

function installDesignTokens(): void {
    const style = document.createElement('style');

    style.setAttribute('data-chart-tokens', '');
    style.textContent =
        `:root { --chart-1: ${lightFill}; --chart-2: #82B536;` +
        ' --chart-3: #BF63F3; --chart-4: #F68909; --chart-5: #1558BC;' +
        ' --chart-neutral: #8C8F97;' +
        ' --foreground: #292A2E; --card: #FFFFFF; }' +
        ` .dark { --chart-1: ${darkFill}; --chart-2: #94C748;` +
        ' --chart-3: #C97CF4; --chart-4: #FCA700; --chart-5: #1558BC;' +
        ' --chart-neutral: #7E8188;' +
        ' --foreground: #CECFD2; --card: #242528; }';
    document.head.appendChild(style);
}

type Wrapper = ReturnType<typeof mount>;

const mounted: Wrapper[] = [];

function mountCanvas(options: AgChartOptions = barOptions()): Wrapper {
    const wrapper = mount(ChartCanvas, { props: { options } });

    mounted.push(wrapper);

    return wrapper;
}

async function settle(): Promise<void> {
    await Promise.resolve();
    await nextTick();
    await nextTick();
}

function lastOptions(): Record<string, unknown> {
    const options = chart.received[chart.received.length - 1];

    if (!options) {
        throw new Error('the chart stub was never handed any options');
    }

    return options;
}

function themeOf(options: Record<string, unknown>): AgChartTheme {
    const theme = options.theme;

    if (theme === null || typeof theme !== 'object') {
        throw new Error('the chart options carry no theme object');
    }

    return theme as AgChartTheme;
}

function downloadImageOf(
    wrapper: Wrapper,
): (options?: DownloadOptions) => Promise<void> {
    const exposed: unknown = wrapper.vm;

    if (
        exposed === null ||
        typeof exposed !== 'object' ||
        !('downloadImage' in exposed)
    ) {
        throw new Error('ChartCanvas exposes no downloadImage method');
    }

    const method: unknown = exposed.downloadImage;

    if (typeof method !== 'function') {
        throw new Error('the exposed downloadImage is not callable');
    }

    return method as (options?: DownloadOptions) => Promise<void>;
}

beforeEach(() => {
    chart.received.length = 0;
    chart.registerChartModules.mockClear();
    chart.download.mockReset();
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document
        .querySelectorAll('style[data-chart-tokens]')
        .forEach((style) => style.remove());
    document.documentElement.classList.remove('dark');
    vi.restoreAllMocks();
});

describe('ChartCanvas mounting', () => {
    it('renders a skeleton on the server and no chart', async () => {
        const html = await renderToString(
            createSSRApp(ChartCanvas, { options: barOptions() }),
        );

        expect(html).toContain('data-chart-skeleton');
        expect(html).not.toContain('data-chart-stub');
    });

    it('swaps the skeleton for the chart once mounted on the client', async () => {
        const wrapper = mountCanvas();

        await settle();

        expect(wrapper.find('[data-chart-canvas]').exists()).toBe(true);
        expect(wrapper.find('[data-chart-stub]').exists()).toBe(true);
        expect(wrapper.find('[data-chart-skeleton]').exists()).toBe(false);
    });

    it('registers the chart modules while setting itself up', async () => {
        mountCanvas();

        await settle();

        expect(chart.registerChartModules).toHaveBeenCalled();
    });
});

describe('ChartCanvas options contract', () => {
    it('hands the library a theme built from the design tokens', async () => {
        installDesignTokens();
        mountCanvas();

        await settle();

        const theme = themeOf(lastOptions());

        expect(theme.baseTheme).toBe('ag-default');
        expect(theme.palette?.fills?.[0]).toBe(lightFill);
    });

    it('never hands a reactive proxy to the library', async () => {
        installDesignTokens();
        mountCanvas();

        await settle();

        expect(isReactive(lastOptions())).toBe(false);
    });

    it('builds a new options object when the caller swaps the props', async () => {
        const wrapper = mountCanvas();

        await settle();

        const before = lastOptions();

        await wrapper.setProps({ options: lineOptions() });
        await settle();

        const after = lastOptions();

        expect(after).not.toBe(before);
        expect(after.series).toEqual([
            { type: 'line', xKey: 'label', yKey: 'value' },
        ]);
    });

    it('passes the tooltip and legend keys of the caller through untouched', async () => {
        mountCanvas(decoratedOptions());

        await settle();

        expect(lastOptions().tooltip).toEqual({ enabled: true });
        expect(lastOptions().legend).toEqual({ position: 'bottom' });
    });

    it('forwards the axes dictionary without turning it into an array', async () => {
        mountCanvas(axisOptions());

        await settle();

        expect(Array.isArray(lastOptions().axes)).toBe(false);
        expect(lastOptions().axes).toEqual({
            x: { type: 'category' },
            y: { type: 'number' },
        });
    });

    it('adds no axes the caller did not ask for', async () => {
        mountCanvas();

        await settle();

        expect(Object.keys(lastOptions())).not.toContain('axes');
    });
});

describe('ChartCanvas appearance switch', () => {
    it('rebuilds the options with the dark theme when the dark class appears', async () => {
        installDesignTokens();

        const wrapper = mountCanvas();

        await settle();

        const before = lastOptions();
        const stub = wrapper.findComponent({ name: 'AgChartsStub' }).vm;

        document.documentElement.classList.add('dark');
        await settle();

        const after = lastOptions();

        expect(after).not.toBe(before);
        expect(themeOf(after).baseTheme).toBe('ag-default-dark');
        expect(themeOf(after).palette?.fills?.[0]).toBe(darkFill);
        expect(wrapper.findComponent({ name: 'AgChartsStub' }).vm).toBe(stub);
    });

    it('disconnects its observer on unmount and stops updating afterwards', async () => {
        installDesignTokens();

        const disconnect = vi.spyOn(MutationObserver.prototype, 'disconnect');
        const wrapper = mountCanvas();

        await settle();

        wrapper.unmount();

        expect(disconnect).toHaveBeenCalled();

        const seen = chart.received.length;

        document.documentElement.classList.add('dark');
        await settle();

        expect(chart.received).toHaveLength(seen);
    });

    it('mounts without design tokens and forwards a theme without a palette', async () => {
        const wrapper = mountCanvas();

        await settle();

        expect(wrapper.find('[data-chart-stub]').exists()).toBe(true);
        expect(Object.keys(themeOf(lastOptions()))).not.toContain('palette');
    });
});

describe('ChartCanvas image download', () => {
    it('hands the download options straight to the chart instance', async () => {
        const wrapper = mountCanvas();

        await settle();

        await downloadImageOf(wrapper)({ fileName: 'auswertung.png' });

        expect(chart.download).toHaveBeenCalledTimes(1);
        expect(chart.download).toHaveBeenCalledWith({
            fileName: 'auswertung.png',
        });
    });

    it('stays quiet when no chart instance is around any more', async () => {
        const wrapper = mountCanvas();

        await settle();

        const downloadImage = downloadImageOf(wrapper);

        wrapper.unmount();

        await expect(downloadImage()).resolves.toBeUndefined();
        expect(chart.download).not.toHaveBeenCalled();
    });
});
