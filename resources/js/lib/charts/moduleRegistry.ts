import {
    AreaSeriesModule,
    BarSeriesModule,
    CategoryAxisModule,
    DonutSeriesModule,
    LegendModule,
    LineSeriesModule,
    LocaleModule,
    ModuleRegistry,
    NumberAxisModule,
    PieSeriesModule,
    TimeAxisModule,
} from 'ag-charts-community';

export function registerChartModules(): void {
    ModuleRegistry.registerModules([
        AreaSeriesModule,
        BarSeriesModule,
        CategoryAxisModule,
        DonutSeriesModule,
        LegendModule,
        LineSeriesModule,
        LocaleModule,
        NumberAxisModule,
        PieSeriesModule,
        TimeAxisModule,
    ]);
}
