import { ModuleRegistry } from 'ag-charts-community';
import { beforeAll, describe, expect, it } from 'vitest';
import { registerChartModules } from '@/lib/charts/moduleRegistry';

const seriesModules = ['area', 'bar', 'donut', 'line', 'pie'];
const axisModules = ['category', 'number', 'time'];
const pluginModules = ['legend', 'locale'];
const chartModules = ['cartesian', 'polar'];

function registeredNames(): string[] {
    return [...ModuleRegistry.listModules()]
        .map((definition) => definition.name)
        .sort();
}

describe('chart module registry', () => {
    beforeAll(() => {
        registerChartModules();
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
