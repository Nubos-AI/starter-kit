import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import {
    DRILL_DOWN_PARAM_KEYS,
    useReportDrillDown,
} from '@/composables/useReportDrillDown';
import type { ReportDrillDownSelection } from '@/types/reports';
import { setUrlDefaults } from '@/wayfinder';

const { drillDownVisit } = vi.hoisted(() => ({
    drillDownVisit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: drillDownVisit,
        reload: vi.fn(),
        on: vi.fn(() => vi.fn()),
    },
    usePage: () => ({
        url: '/nubos/reports/01REPORT0000000000000001/edit',
        props: {},
    }),
}));

const DRILL_DOWN_REPORT_ID = '01REPORT0000000000000001';

const DRILL_DOWN_LIST_PATH = '/nubos/records/companies';

const SEGMENT_ID = '01SEGMENT00000000000001';

const BUCKET_TOKEN = 'v:2026-08-01T00:00:00+02:00';

const COLON_TOKEN = 'v:https://example.test:8443/a:b';

interface DrillDownSource {
    reportId: string;
    objectTypeSlug: string;
    selection: ReportDrillDownSelection;
}

function drillDownSelection(
    overrides: Partial<ReportDrillDownSelection> = {},
): ReportDrillDownSelection {
    return { group: 'v:won', series: null, ...overrides };
}

function drillDownSource(
    selection: ReportDrillDownSelection = drillDownSelection(),
): DrillDownSource {
    return {
        reportId: DRILL_DOWN_REPORT_ID,
        objectTypeSlug: 'companies',
        selection,
    };
}

function setDrillDownUrl(search: string): void {
    window.history.replaceState(null, '', `${DRILL_DOWN_LIST_PATH}${search}`);
}

function queryOf(target: string): URLSearchParams {
    const marker = target.indexOf('?');

    return new URLSearchParams(marker === -1 ? '' : target.slice(marker + 1));
}

function searchOf(target: string): string {
    const marker = target.indexOf('?');

    if (marker === -1) {
        throw new Error('the drill-down target carries no query string');
    }

    return target.slice(marker);
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: 'nubos' });
    setDrillDownUrl('');
    drillDownVisit.mockReset();
});

afterEach(() => {
    setDrillDownUrl('');
    setUrlDefaults({});
});

describe('useReportDrillDown — the target it builds', () => {
    it('addresses the record list of the active team and names the report and the group', () => {
        const { target } = useReportDrillDown();
        const url = target(drillDownSource());
        const query = queryOf(url);

        expect(url.startsWith(`${DRILL_DOWN_LIST_PATH}?`)).toBe(true);
        expect(query.get('report')).toBe(DRILL_DOWN_REPORT_ID);
        expect(query.get('group')).toBe('v:won');
        expect(url).toContain('group=v%3Awon');
    });

    it('leaves the series out entirely for a report with a single dimension', () => {
        const { target } = useReportDrillDown();

        expect(queryOf(target(drillDownSource())).has('series')).toBe(false);
    });

    it('carries the series of a two dimensional report', () => {
        const { target } = useReportDrillDown();
        const url = target(
            drillDownSource(drillDownSelection({ series: 'v:2026' })),
        );

        expect(queryOf(url).get('series')).toBe('v:2026');
        expect(url).toContain('series=v%3A2026');
    });

    it('encodes a group without a value as a token instead of dropping the parameter', () => {
        const { target } = useReportDrillDown();
        const url = target(
            drillDownSource(drillDownSelection({ group: 'null' })),
        );

        expect(queryOf(url).get('group')).toBe('null');
        expect(url).toContain('group=null');
    });

    it('keeps a series without a value apart from a report without a series', () => {
        const { target } = useReportDrillDown();
        const empty = target(
            drillDownSource(drillDownSelection({ series: 'null' })),
        );
        const absent = target(drillDownSource(drillDownSelection()));

        expect(queryOf(empty).get('series')).toBe('null');
        expect(queryOf(absent).has('series')).toBe(false);
        expect(empty).not.toBe(absent);
    });
});

describe('useReportDrillDown — the values survive the round trip', () => {
    it('carries a time bucket including its offset and reads it back unchanged', () => {
        const { target } = useReportDrillDown();
        const url = target(
            drillDownSource(drillDownSelection({ group: BUCKET_TOKEN })),
        );

        expect(url).toContain('group=v%3A2026-08-01T00%3A00%3A00%2B02%3A00');

        setDrillDownUrl(searchOf(url));

        expect(useReportDrillDown().active.value).toEqual({
            report: DRILL_DOWN_REPORT_ID,
            group: BUCKET_TOKEN,
            series: null,
        });
    });

    it('carries a group value that contains colons of its own', () => {
        const { target } = useReportDrillDown();
        const url = target(
            drillDownSource(drillDownSelection({ group: COLON_TOKEN })),
        );

        setDrillDownUrl(searchOf(url));

        expect(useReportDrillDown().active.value?.group).toBe(COLON_TOKEN);
    });

    it('carries a group value that reads like the empty marker', () => {
        const { target } = useReportDrillDown();
        const url = target(
            drillDownSource(drillDownSelection({ group: 'v:null' })),
        );

        setDrillDownUrl(searchOf(url));

        expect(useReportDrillDown().active.value?.group).toBe('v:null');
    });
});

describe('useReportDrillDown — opening the list', () => {
    it('navigates through the Inertia router and never through the browser location', () => {
        const { target, open } = useReportDrillDown();
        const expected = target(drillDownSource());
        const before = window.location.href;

        open(drillDownSource());

        expect(drillDownVisit).toHaveBeenCalledTimes(1);
        expect(String(drillDownVisit.mock.calls[0][0])).toBe(expected);
        expect(window.location.href).toBe(before);
    });

    it('refuses to open the collected bucket of the group axis', () => {
        const { open } = useReportDrillDown();

        open(drillDownSource(drillDownSelection({ group: 'other' })));

        expect(drillDownVisit).not.toHaveBeenCalled();
    });

    it('refuses to open the collected bucket of the series axis', () => {
        const { open } = useReportDrillDown();

        open(drillDownSource(drillDownSelection({ series: 'other' })));

        expect(drillDownVisit).not.toHaveBeenCalled();
    });
});

describe('useReportDrillDown — reading the parameters back', () => {
    it('reads a complete pair out of the URL', () => {
        setDrillDownUrl(`?report=${DRILL_DOWN_REPORT_ID}&group=v%3Awon`);

        expect(useReportDrillDown().active.value).toEqual({
            report: DRILL_DOWN_REPORT_ID,
            group: 'v:won',
            series: null,
        });
    });

    it('reads the series of a two dimensional drill-down', () => {
        setDrillDownUrl(
            `?report=${DRILL_DOWN_REPORT_ID}&group=v%3ANord&series=v%3A2026`,
        );

        expect(useReportDrillDown().active.value).toEqual({
            report: DRILL_DOWN_REPORT_ID,
            group: 'v:Nord',
            series: 'v:2026',
        });
    });

    it('reports no drill-down at all on a plain record list URL', () => {
        setDrillDownUrl('');

        expect(useReportDrillDown().active.value).toBeNull();
    });

    it('reads a half written pair as no drill-down rather than applying it in part', () => {
        setDrillDownUrl(`?report=${DRILL_DOWN_REPORT_ID}`);

        expect(useReportDrillDown().active.value).toBeNull();

        setDrillDownUrl('?group=v%3Awon');

        expect(useReportDrillDown().active.value).toBeNull();
    });

    it('leaves an unrelated segment link untouched', () => {
        setDrillDownUrl('?segment=01SEGMENT00000000000001');

        expect(useReportDrillDown().active.value).toBeNull();
    });
});

describe('useReportDrillDown — retiring an active drill-down', () => {
    it('reports no drill-down any more once it has been cleared', () => {
        setDrillDownUrl(`?report=${DRILL_DOWN_REPORT_ID}&group=v%3Awon`);

        const { active, clear } = useReportDrillDown();

        expect(active.value).not.toBeNull();

        clear();

        expect(active.value).toBeNull();
    });

    it('writes nothing to the URL itself and keeps a foreign parameter alive', async () => {
        setDrillDownUrl(
            `?report=${DRILL_DOWN_REPORT_ID}&group=v%3Awon&segment=${SEGMENT_ID}`,
        );

        useReportDrillDown().clear();
        await nextTick();

        expect(new URLSearchParams(window.location.search).get('segment')).toBe(
            SEGMENT_ID,
        );
    });
});

const DRILL_DOWN_DASHBOARD_ID = '01DASHBOARD00000000000001';

const DRILL_DOWN_WIDGET_ID = '01WIDGET000000000000000A';

interface WidgetDrillDownSource {
    dashboardId: string;
    widgetId: string;
    objectTypeSlug: string;
    selection: ReportDrillDownSelection;
}

function widgetDrillDownSource(
    selection: ReportDrillDownSelection = drillDownSelection(),
): WidgetDrillDownSource {
    return {
        dashboardId: DRILL_DOWN_DASHBOARD_ID,
        widgetId: DRILL_DOWN_WIDGET_ID,
        objectTypeSlug: 'companies',
        selection,
    };
}

describe('useReportDrillDown — a dashboard widget as the second source', () => {
    it('names the dashboard and the widget instead of a report', () => {
        const { target } = useReportDrillDown();
        const url = target(widgetDrillDownSource());
        const query = queryOf(url);

        expect(url.startsWith(`${DRILL_DOWN_LIST_PATH}?`)).toBe(true);
        expect(query.get('dashboard')).toBe(DRILL_DOWN_DASHBOARD_ID);
        expect(query.get('widget')).toBe(DRILL_DOWN_WIDGET_ID);
        expect(query.get('group')).toBe('v:won');
        expect(query.has('report')).toBe(false);
    });

    it('leaves the series out for a widget with a single dimension', () => {
        const { target } = useReportDrillDown();

        expect(queryOf(target(widgetDrillDownSource())).has('series')).toBe(
            false,
        );
    });

    it('carries the series of a widget with two dimensions', () => {
        const { target } = useReportDrillDown();
        const url = target(
            widgetDrillDownSource(drillDownSelection({ series: 'v:2026' })),
        );

        expect(queryOf(url).get('series')).toBe('v:2026');
    });

    it('opens the list through the Inertia router for a widget as well', () => {
        const { target, open } = useReportDrillDown();
        const expected = target(widgetDrillDownSource());

        open(widgetDrillDownSource());

        expect(drillDownVisit).toHaveBeenCalledTimes(1);
        expect(String(drillDownVisit.mock.calls[0][0])).toBe(expected);
    });

    it('refuses the collected bucket for a widget just as for a report', () => {
        const { open } = useReportDrillDown();

        open(widgetDrillDownSource(drillDownSelection({ group: 'other' })));

        expect(drillDownVisit).not.toHaveBeenCalled();

        open(widgetDrillDownSource(drillDownSelection({ series: 'other' })));

        expect(drillDownVisit).not.toHaveBeenCalled();

        open(widgetDrillDownSource());

        expect(drillDownVisit).toHaveBeenCalledTimes(1);
    });
});

describe('useReportDrillDown — reading a widget drill-down back', () => {
    it('reads the dashboard, the widget and the group out of the URL', () => {
        setDrillDownUrl(
            `?dashboard=${DRILL_DOWN_DASHBOARD_ID}&widget=${DRILL_DOWN_WIDGET_ID}&group=v%3Awon`,
        );

        expect(useReportDrillDown().active.value).toEqual({
            dashboard: DRILL_DOWN_DASHBOARD_ID,
            widget: DRILL_DOWN_WIDGET_ID,
            group: 'v:won',
            series: null,
        });
    });

    it('reads the series of a two dimensional widget drill-down', () => {
        setDrillDownUrl(
            `?dashboard=${DRILL_DOWN_DASHBOARD_ID}&widget=${DRILL_DOWN_WIDGET_ID}&group=v%3ANord&series=v%3A2026`,
        );

        expect(useReportDrillDown().active.value).toEqual({
            dashboard: DRILL_DOWN_DASHBOARD_ID,
            widget: DRILL_DOWN_WIDGET_ID,
            group: 'v:Nord',
            series: 'v:2026',
        });
    });

    it('reads a widget link without a group as no drill-down at all', () => {
        setDrillDownUrl(
            `?dashboard=${DRILL_DOWN_DASHBOARD_ID}&widget=${DRILL_DOWN_WIDGET_ID}`,
        );

        expect(useReportDrillDown().active.value).toBeNull();
    });

    it('degrades a link naming both sources to no drill-down instead of a server refusal', () => {
        setDrillDownUrl(
            `?report=${DRILL_DOWN_REPORT_ID}&dashboard=${DRILL_DOWN_DASHBOARD_ID}&widget=${DRILL_DOWN_WIDGET_ID}&group=v%3Awon`,
        );

        expect(useReportDrillDown().active.value).toBeNull();

        setDrillDownUrl(`?report=${DRILL_DOWN_REPORT_ID}&group=v%3Awon`);

        expect(useReportDrillDown().active.value).not.toBeNull();
    });
});

describe('useReportDrillDown — the parameters a segment switch clears', () => {
    it('lists both drill-down sources so a segment switch sweeps them out of the URL', () => {
        const keys = [...DRILL_DOWN_PARAM_KEYS];

        expect([...keys].sort()).toEqual(
            [...['dashboard', 'group', 'report', 'series', 'widget']].sort(),
        );
        expect(new Set(keys).size).toBe(keys.length);
    });
});
