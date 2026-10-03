import { router } from '@inertiajs/vue3';
import { useUrlSearchParams } from '@vueuse/core';
import type { ComputedRef } from 'vue';
import { computed, ref, watch } from 'vue';
import RecordGridsController from '@/actions/App/Http/Controllers/Engine/RecordGridsController';
import { readUrlParam } from '@/lib/urlParams';
import type {
    ReportDrillDownParams,
    ReportDrillDownSelection,
} from '@/types/reports';
import { isCollectorToken } from '@/types/reports';

export const DRILL_DOWN_PARAM_KEYS = [
    'report',
    'dashboard',
    'widget',
    'group',
    'series',
] as const;

export type DrillDownParamKey = (typeof DRILL_DOWN_PARAM_KEYS)[number];

export interface ReportDrillDownReportSource {
    reportId: string;
    objectTypeSlug: string;
}

export interface ReportDrillDownWidgetSource {
    dashboardId: string;
    widgetId: string;
    objectTypeSlug: string;
}

export type ReportDrillDownSource =
    | ReportDrillDownReportSource
    | ReportDrillDownWidgetSource;

export type ReportDrillDownRequest = ReportDrillDownSource & {
    selection: ReportDrillDownSelection;
};

export interface UseReportDrillDownReturn {
    target: (request: ReportDrillDownRequest) => string;
    open: (request: ReportDrillDownRequest) => void;
    clear: () => void;
    active: ComputedRef<ReportDrillDownParams | null>;
}

type DrillDownQuery = {
    [Key in DrillDownParamKey]?: string | null;
};

export function useReportDrillDown(): UseReportDrillDownReturn {
    const params = useUrlSearchParams<DrillDownQuery>('history');
    const isCleared = ref(false);

    watch(params, () => {
        isCleared.value = false;
    });

    function target(request: ReportDrillDownRequest): string {
        const query: Record<string, string> =
            'reportId' in request
                ? { report: request.reportId, group: request.selection.group }
                : {
                      dashboard: request.dashboardId,
                      widget: request.widgetId,
                      group: request.selection.group,
                  };

        if (request.selection.series !== null) {
            query.series = request.selection.series;
        }

        return RecordGridsController.index.url(
            { objectType: request.objectTypeSlug },
            { query },
        );
    }

    function isOffered(selection: ReportDrillDownSelection): boolean {
        if (isCollectorToken(selection.group)) {
            return false;
        }

        return selection.series === null || !isCollectorToken(selection.series);
    }

    function open(request: ReportDrillDownRequest): void {
        if (!isOffered(request.selection)) {
            return;
        }

        router.visit(target(request));
    }

    function clear(): void {
        isCleared.value = true;
    }

    const active = computed<ReportDrillDownParams | null>(() => {
        if (isCleared.value) {
            return null;
        }

        const report = readUrlParam(params, 'report');
        const dashboard = readUrlParam(params, 'dashboard');
        const widget = readUrlParam(params, 'widget');
        const group = readUrlParam(params, 'group');

        if (group === null) {
            return null;
        }

        const series = readUrlParam(params, 'series');

        if (report !== null) {
            return dashboard === null && widget === null
                ? { report, group, series }
                : null;
        }

        return dashboard !== null && widget !== null
            ? { dashboard, widget, group, series }
            : null;
    });

    return { target, open, clear, active };
}
