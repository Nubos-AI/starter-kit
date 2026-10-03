import { ModuleRegistry } from 'ag-charts-community';
import { beforeAll, describe, expect, it } from 'vitest';
import { registerChartModules } from '@/lib/charts/moduleRegistry';

const seriesModules = ['area', 'bar', 'donut', 'line', 'pie'];
const axisModules = ['category', 'number', 'time'];
const pluginModules = ['legend', 'locale'];
const chartModules = ['cartesian', 'polar'];
const bulkOnlyModules = [
    'bubble',
    'crossLines',
    'grouped-category',
    'histogram',
    'log',
    'scatter',
    'sparkline',
    'unit-time',
];

function registeredNames(): string[] {
    return [...ModuleRegistry.listModules()]
        .map((definition) => definition.name)
        .sort();
}

function registeredAmong(names: string[]): string[] {
    return names.filter((name) => ModuleRegistry.hasModule(name));
}

describe('chart module registry', () => {
    beforeAll(() => {
        registerChartModules();
    });

    it('registers every series type M003 renders', () => {
        expect(registeredAmong(seriesModules)).toEqual(seriesModules);
    });

    it('registers the legend, which is a module of its own', () => {
        expect(ModuleRegistry.hasModule('legend')).toBe(true);
    });

    it('registers all three axis types, which are modules of their own', () => {
        expect(registeredAmong(axisModules)).toEqual(axisModules);
    });

    it('registers the locale module the German interface needs', () => {
        expect(ModuleRegistry.hasModule('locale')).toBe(true);
    });

    it('registers no tooltip module, because tooltips are a core option', () => {
        expect(ModuleRegistry.hasModule('tooltip')).toBe(false);
    });

    it('pulls in nothing a bulk registration would have added', () => {
        expect(registeredAmong(bulkOnlyModules)).toEqual([]);
    });

    it('registers exactly the ten modules plus their chart dependencies', () => {
        const expected = [
            ...seriesModules,
            ...axisModules,
            ...pluginModules,
            ...chartModules,
        ].sort();

        expect(registeredNames()).toEqual(expected);
    });

    it('survives a second call and leaves the registry unchanged', () => {
        const before = registeredNames();

        expect(() => registerChartModules()).not.toThrow();
        expect(registeredNames()).toEqual(before);
    });
});
