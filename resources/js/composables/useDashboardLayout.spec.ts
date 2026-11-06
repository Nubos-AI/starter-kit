import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Ref } from 'vue';
import { effectScope, nextTick, ref } from 'vue';
import DashboardWidgetsController from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetsController';
import { useDashboardLayout } from '@/composables/useDashboardLayout';
import type {
    DashboardColumnSpan,
    DashboardWidgetMeta,
} from '@/types/dashboards';
import { setUrlDefaults } from '@/wayfinder';

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: { error: toastError, success: vi.fn() },
}));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const ACTIVE_TEAM = 'nubos';

const ENGLISH_WORDS = /\b(the|this|that|your|failed|error|could|please)\b/i;

const ALLOWED_SPANS = [1, 2, 3];

type FetchMock = ReturnType<typeof vi.fn>;

type FetchHandler = (input: string, init?: RequestInit) => Promise<Response>;

interface Deferred<T> {
    promise: Promise<T>;
    resolve: (value: T) => void;
}

interface ScopedLayout {
    layout: ReturnType<typeof useDashboardLayout>;
    stop: () => void;
}

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

function deferred<T>(): Deferred<T> {
    let resolve: (value: T) => void = () => undefined;

    const promise = new Promise<T>((resolveFn) => {
        resolve = resolveFn;
    });

    return { promise, resolve };
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function widget(
    overrides: Partial<DashboardWidgetMeta> = {},
): DashboardWidgetMeta {
    return {
        id: 'w1',
        dashboard_id: DASHBOARD_ID,
        report_id: '01REPORT0000000000000001',
        title: 'Erste Kachel',
        chart_type: 'metric',
        goal_id: null,
        definition: null,
        position: 0,
        column_span: 1,
        updated_at: '2026-08-10T12:00:00+00:00',
        ...overrides,
    };
}

function outOfRangeSpan(value: number): DashboardColumnSpan {
    return value as DashboardColumnSpan;
}

function threeWidgets(): DashboardWidgetMeta[] {
    return [
        widget({
            id: 'w1',
            title: 'Erste Kachel',
            position: 0,
            column_span: 1,
        }),
        widget({
            id: 'w2',
            title: 'Zweite Kachel',
            position: 1,
            column_span: 2,
        }),
        widget({
            id: 'w3',
            title: 'Dritte Kachel',
            position: 2,
            column_span: 3,
        }),
    ];
}

function stubFetch(handler: FetchHandler): FetchMock {
    const mock = vi.fn(handler);

    vi.stubGlobal('fetch', mock);

    return mock;
}

function respondingFetch(): FetchMock {
    return stubFetch(() =>
        Promise.resolve(jsonResponse(200, { data: { id: DASHBOARD_ID } })),
    );
}

function pathOf(url: string): string {
    return new URL(url, 'http://localhost').pathname;
}

function layoutRoute(): string {
    return DashboardWidgetsController.updateLayout.url({
        dashboard: DASHBOARD_ID,
    });
}

function storeRoute(): string {
    return DashboardWidgetsController.store.url({ dashboard: DASHBOARD_ID });
}

function destroyRoute(widgetId: string): string {
    return DashboardWidgetsController.destroy.url({
        dashboard: DASHBOARD_ID,
        widget: widgetId,
    });
}

function callsTo(
    fetchMock: FetchMock,
    method: string,
    route: string,
): Array<[string, RequestInit]> {
    return fetchMock.mock.calls.filter(([url, init]) => {
        const used = (init as RequestInit | undefined)?.method ?? 'GET';

        return used === method && pathOf(String(url)) === pathOf(route);
    }) as Array<[string, RequestInit]>;
}

function layoutCalls(fetchMock: FetchMock): Array<[string, RequestInit]> {
    return callsTo(fetchMock, 'PUT', layoutRoute());
}

function bodyOf(call: [string, RequestInit]): Record<string, unknown> {
    const body = call[1].body;

    if (typeof body !== 'string') {
        throw new Error('the arrangement request carries no JSON body');
    }

    const parsed: unknown = JSON.parse(body);

    if (parsed === null || typeof parsed !== 'object') {
        throw new Error('the arrangement request body is no plain object');
    }

    return parsed as Record<string, unknown>;
}

function arrangementOf(
    call: [string, RequestInit],
): Array<Record<string, unknown>> {
    const entries = bodyOf(call).widgets;

    if (!Array.isArray(entries)) {
        throw new Error('the arrangement request carries no widget list');
    }

    return entries as Array<Record<string, unknown>>;
}

function idsOf(call: [string, RequestInit]): string[] {
    return arrangementOf(call).map((entry) => String(entry.id));
}

function spansOf(call: [string, RequestInit]): number[] {
    return arrangementOf(call).map((entry) => Number(entry.column_span));
}

function scoped(source: Ref<DashboardWidgetMeta[]>): ScopedLayout {
    const scope = effectScope();
    const created = scope.run(() =>
        useDashboardLayout(DASHBOARD_ID, () => source.value),
    );

    if (created === undefined) {
        throw new Error('The layout composable did not run inside the scope.');
    }

    return { layout: created, stop: () => scope.stop() };
}

function orderOf(layout: ReturnType<typeof useDashboardLayout>): string[] {
    return layout.widgets.value.map((entry) => entry.id);
}

function spanOf(
    layout: ReturnType<typeof useDashboardLayout>,
    widgetId: string,
): number {
    const found = layout.widgets.value.find((entry) => entry.id === widgetId);

    if (found === undefined) {
        throw new Error(`Widget "${widgetId}" is missing from the arrangement`);
    }

    return found.column_span;
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: ACTIVE_TEAM });
    toastError.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
    setUrlDefaults({});
});

describe('useDashboardLayout — one request carrying the whole arrangement', () => {
    it('lists every widget exactly once and in the new order after a move', async () => {
        const fetchMock = respondingFetch();
        const { layout, stop } = scoped(ref(threeWidgets()));

        layout.moveBefore('w3', 'w1');
        await flushPromises();

        const calls = layoutCalls(fetchMock);

        expect(calls).toHaveLength(1);

        const ids = idsOf(calls[0]);

        expect([...ids].sort()).toEqual([...['w1', 'w2', 'w3']].sort());
        expect(new Set(ids).size).toBe(ids.length);
        expect(ids).toEqual(['w3', 'w1', 'w2']);
        expect(orderOf(layout)).toEqual(['w3', 'w1', 'w2']);

        stop();
    });

    it('carries a column span for every entry and never a position', async () => {
        const fetchMock = respondingFetch();
        const { layout, stop } = scoped(ref(threeWidgets()));

        layout.moveBy('w1', 1);
        await flushPromises();

        const entries = arrangementOf(layoutCalls(fetchMock)[0]);

        expect(entries).toHaveLength(3);

        entries.forEach((entry) => {
            expect(Object.keys(entry).sort()).toEqual(['column_span', 'id']);
            expect(typeof entry.column_span).toBe('number');
        });

        stop();
    });

    it('clamps a span above and below the allowed range before it reaches the wire', async () => {
        const fetchMock = respondingFetch();
        const { layout, stop } = scoped(ref(threeWidgets()));

        layout.setSpan('w1', outOfRangeSpan(5));
        await flushPromises();

        expect(spanOf(layout, 'w1')).toBe(3);
        expect(spansOf(layoutCalls(fetchMock)[0])).toEqual([3, 2, 3]);

        layout.setSpan('w1', outOfRangeSpan(0));
        await flushPromises();

        expect(spanOf(layout, 'w1')).toBe(1);

        const calls = layoutCalls(fetchMock);

        expect(calls).toHaveLength(2);

        calls.forEach((call) => {
            spansOf(call).forEach((span) => {
                expect(ALLOWED_SPANS).toContain(span);
            });
        });

        stop();
    });

    it('changes nothing and sends nothing at the two edges of the arrangement', async () => {
        const fetchMock = respondingFetch();
        const { layout, stop } = scoped(ref(threeWidgets()));

        layout.moveBy('w1', -1);
        layout.moveBy('w3', 1);
        await flushPromises();

        expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);
        expect(layoutCalls(fetchMock)).toHaveLength(0);
        expect(fetchMock).not.toHaveBeenCalled();

        stop();
    });

    it('sends the settled arrangement once more instead of one request per change', async () => {
        const pending = deferred<Response>();
        const answers: Array<Promise<Response>> = [pending.promise];

        const fetchMock = stubFetch(() => {
            const next = answers.shift();

            return (
                next ??
                Promise.resolve(
                    jsonResponse(200, { data: { id: DASHBOARD_ID } }),
                )
            );
        });

        const { layout, stop } = scoped(ref(threeWidgets()));

        layout.moveBy('w1', 1);
        layout.setSpan('w3', 2);

        expect(layoutCalls(fetchMock)).toHaveLength(1);

        pending.resolve(jsonResponse(200, { data: { id: DASHBOARD_ID } }));
        await flushPromises();

        const calls = layoutCalls(fetchMock);

        expect(calls).toHaveLength(2);
        expect(idsOf(calls[1])).toEqual(['w2', 'w1', 'w3']);
        expect(
            arrangementOf(calls[1]).find((entry) => entry.id === 'w3')
                ?.column_span,
        ).toBe(2);

        stop();
    });
});

describe('useDashboardLayout — when the server refuses', () => {
    it('puts the previous order back and says so instead of diverging silently', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(500, {})));

        const { layout, stop } = scoped(ref(threeWidgets()));

        layout.moveBefore('w3', 'w1');

        expect(orderOf(layout)).toEqual(['w3', 'w1', 'w2']);

        await flushPromises();

        expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);
        expect(layout.error.value).not.toBeNull();
        expect(String(layout.error.value).length).toBeGreaterThan(10);
        expect(String(layout.error.value)).not.toMatch(ENGLISH_WORDS);
        expect(toastError).toHaveBeenCalledTimes(1);

        stop();
    });

    it('states the reason the server gave for refusing the arrangement', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(422, {
                    message: 'Die Kachel gehört zu einem anderen Dashboard.',
                }),
            ),
        );

        const { layout, stop } = scoped(ref(threeWidgets()));

        layout.moveBefore('w3', 'w1');
        await flushPromises();

        expect(layout.error.value).toBe(
            'Die Kachel gehört zu einem anderen Dashboard.',
        );
        expect(toastError).toHaveBeenCalledWith(
            'Die Kachel gehört zu einem anderen Dashboard.',
        );

        stop();
    });

    it('states the reason the server gave for refusing to remove a tile', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(403, {
                    message: 'Sie dürfen diese Kachel nicht entfernen.',
                }),
            ),
        );

        const { layout, stop } = scoped(ref(threeWidgets()));

        await expect(layout.removeWidget('w2')).resolves.toBe(false);

        expect(layout.error.value).toBe(
            'Sie dürfen diese Kachel nicht entfernen.',
        );
        expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);

        stop();
    });

    it('states the reason a widget write was refused when no field error names it', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(422, {
                    message: 'Der Bericht ist nicht mehr verfügbar.',
                }),
            ),
        );

        const { layout, stop } = scoped(ref(threeWidgets()));

        await expect(
            layout.addWidget({ report_id: 'gone' }),
        ).resolves.toBeNull();

        expect(layout.error.value).toBe(
            'Der Bericht ist nicht mehr verfügbar.',
        );
        expect(layout.validationErrors.value).toEqual({});

        stop();
    });
});

describe('useDashboardLayout — announcing a move', () => {
    it('announces the move in German and names the moved tile', async () => {
        respondingFetch();

        const { layout, stop } = scoped(ref(threeWidgets()));

        expect(layout.announcement.value).toBe('');

        layout.moveBy('w1', 1);
        await nextTick();
        await nextTick();

        expect(layout.announcement.value.length).toBeGreaterThan(10);
        expect(layout.announcement.value).toContain('Erste Kachel');
        expect(layout.announcement.value).not.toMatch(ENGLISH_WORDS);

        await flushPromises();
        stop();
    });
});

describe('useDashboardLayout — the server stays the source of truth', () => {
    it('adopts a freshly delivered arrangement without writing it back', async () => {
        const fetchMock = respondingFetch();
        const source = ref(threeWidgets());
        const { layout, stop } = scoped(source);

        source.value = [
            widget({ id: 'w9', title: 'Neue Kachel', column_span: 2 }),
            widget({ id: 'w1', title: 'Erste Kachel', column_span: 1 }),
        ];
        await flushPromises();

        expect(orderOf(layout)).toEqual(['w9', 'w1']);
        expect(spanOf(layout, 'w9')).toBe(2);
        expect(layoutCalls(fetchMock)).toHaveLength(0);

        stop();
    });
});

describe('useDashboardLayout — adding and removing a widget', () => {
    it('never rearranges while adding or removing, but does while moving', async () => {
        const fetchMock = stubFetch((_url, init) => {
            const method = init?.method ?? 'GET';

            if (method === 'POST') {
                return Promise.resolve(
                    jsonResponse(201, {
                        data: widget({ id: 'w4', title: 'Vierte Kachel' }),
                    }),
                );
            }

            if (method === 'DELETE') {
                return Promise.resolve(jsonResponse(204, null));
            }

            return Promise.resolve(
                jsonResponse(200, { data: { id: DASHBOARD_ID } }),
            );
        });

        const { layout, stop } = scoped(ref(threeWidgets()));

        await layout.addWidget({ report_id: 'r-1', chart_type: 'metric' });
        await layout.removeWidget('w2');
        await flushPromises();

        expect(callsTo(fetchMock, 'POST', storeRoute())).toHaveLength(1);
        expect(callsTo(fetchMock, 'DELETE', destroyRoute('w2'))).toHaveLength(
            1,
        );
        expect(layoutCalls(fetchMock)).toHaveLength(0);

        layout.moveBy('w1', 1);
        await flushPromises();

        expect(layoutCalls(fetchMock)).toHaveLength(1);

        stop();
    });
});

describe('useDashboardLayout — tearing the scope down', () => {
    it('aborts the arrangement in flight and stops touching its state afterwards', async () => {
        const pending = deferred<Response>();
        const fetchMock = stubFetch(() => pending.promise);

        const { layout, stop } = scoped(ref(threeWidgets()));

        layout.moveBefore('w3', 'w1');

        expect(layoutCalls(fetchMock)).toHaveLength(1);

        stop();

        expect(layoutCalls(fetchMock)[0][1].signal?.aborted).toBe(true);

        pending.resolve(jsonResponse(500, { message: 'too late' }));
        await flushPromises();

        expect(layout.error.value).toBeNull();
        expect(toastError).not.toHaveBeenCalled();
    });
});
