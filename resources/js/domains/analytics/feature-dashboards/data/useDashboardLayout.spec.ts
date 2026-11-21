import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Ref } from 'vue';
import { effectScope, nextTick, ref } from 'vue';
import DashboardWidgetsController from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetsController';
import { t } from '@/shared/testing/i18n';
import { serveEmpty, serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import type { RecordedRequest } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { DashboardColumnSpan, DashboardWidgetMeta } from '../../data/types/dashboards';
import {
  LAYOUT_ERROR_MESSAGE,
  useDashboardLayout,
  WIDGET_CREATE_ERROR_MESSAGE,
  WIDGET_REMOVE_ERROR_MESSAGE,
} from './useDashboardLayout';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
  toast: { error: toastError, success: vi.fn() },
}));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const ACTIVE_TEAM = 'nubos';

const ENGLISH_WORDS = /\b(the|this|that|your|failed|error|could|please)\b/i;

const ALLOWED_SPANS = [1, 2, 3];

interface ScopedLayout {
  layout: ReturnType<typeof useDashboardLayout>;
  stop: () => void;
}

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

function widget(overrides: Partial<DashboardWidgetMeta> = {}): DashboardWidgetMeta {
  return {
    id: 'w1',
    dashboard_id: DASHBOARD_ID,
    report_id: '01REPORT0000000000000001',
    title: 'Erste Kachel',
    chart_type: 'metric',
    goal_id: null,
    source_key: null,
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

function layoutRoute(): ReturnType<typeof DashboardWidgetsController.updateLayout> {
  return DashboardWidgetsController.updateLayout({ dashboard: DASHBOARD_ID });
}

function storeRoute(): ReturnType<typeof DashboardWidgetsController.store> {
  return DashboardWidgetsController.store({ dashboard: DASHBOARD_ID });
}

function updateRoute(widgetId: string): ReturnType<typeof DashboardWidgetsController.update> {
  return DashboardWidgetsController.update({
    dashboard: DASHBOARD_ID,
    widget: widgetId,
  });
}

function destroyRoute(widgetId: string): ReturnType<typeof DashboardWidgetsController.destroy> {
  return DashboardWidgetsController.destroy({
    dashboard: DASHBOARD_ID,
    widget: widgetId,
  });
}

function serveLayout(): ReturnType<typeof serveJson> {
  return serveJson(layoutRoute(), { data: { id: DASHBOARD_ID } });
}

function bodyOf(request: RecordedRequest | undefined): Record<string, unknown> {
  const body = request?.body;

  if (body === null || typeof body !== 'object') {
    throw new Error('the arrangement request carries no JSON body');
  }

  return body as Record<string, unknown>;
}

function arrangementOf(request: RecordedRequest | undefined): Array<Record<string, unknown>> {
  const entries = bodyOf(request).widgets;

  if (!Array.isArray(entries)) {
    throw new Error('the arrangement request carries no widget list');
  }

  return entries as Array<Record<string, unknown>>;
}

function idsOf(request: RecordedRequest | undefined): string[] {
  return arrangementOf(request).map((entry) => String(entry.id));
}

function spansOf(request: RecordedRequest | undefined): number[] {
  return arrangementOf(request).map((entry) => Number(entry.column_span));
}

function scoped(source: Ref<DashboardWidgetMeta[]>): ScopedLayout {
  const scope = effectScope();
  const created = scope.run(() => useDashboardLayout(DASHBOARD_ID, () => source.value));

  if (created === undefined) {
    throw new Error('The layout composable did not run inside the scope.');
  }

  return { layout: created, stop: () => scope.stop() };
}

function orderOf(layout: ReturnType<typeof useDashboardLayout>): string[] {
  return layout.widgets.value.map((entry) => entry.id);
}

function spanOf(layout: ReturnType<typeof useDashboardLayout>, widgetId: string): number {
  const found = layout.widgets.value.find((entry) => entry.id === widgetId);

  if (found === undefined) {
    throw new Error(`Widget "${widgetId}" is missing from the arrangement`);
  }

  return found.column_span;
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: ACTIVE_TEAM });
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  toastError.mockReset();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  setUrlDefaults({});
});

describe('useDashboardLayout — one request carrying the whole arrangement', () => {
  it('lists every widget exactly once and in the new order after a move', async () => {
    const served = serveLayout();
    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.moveBefore('w3', 'w1');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    await flushPromises();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/${ACTIVE_TEAM}/dashboards/${DASHBOARD_ID}/widgets/layout`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');

    const ids = idsOf(served.lastRequest());

    expect([...ids].sort()).toEqual([...['w1', 'w2', 'w3']].sort());
    expect(new Set(ids).size).toBe(ids.length);
    expect(ids).toEqual(['w3', 'w1', 'w2']);
    expect(orderOf(layout)).toEqual(['w3', 'w1', 'w2']);

    stop();
  });

  it('carries a column span for every entry and never a position', async () => {
    const served = serveLayout();
    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.moveBy('w1', 1);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    const entries = arrangementOf(served.lastRequest());

    expect(entries).toHaveLength(3);

    entries.forEach((entry) => {
      expect(Object.keys(entry).sort()).toEqual(['column_span', 'id']);
      expect(typeof entry.column_span).toBe('number');
    });

    stop();
  });

  it('clamps a span above and below the allowed range before it reaches the wire', async () => {
    const served = serveLayout();
    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.setSpan('w1', outOfRangeSpan(5));
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    await flushPromises();

    expect(spanOf(layout, 'w1')).toBe(3);
    expect(spansOf(served.requests[0])).toEqual([3, 2, 3]);

    layout.setSpan('w1', outOfRangeSpan(0));
    await vi.waitFor(() => expect(served.requests).toHaveLength(2));

    expect(spanOf(layout, 'w1')).toBe(1);

    served.requests.forEach((request) => {
      spansOf(request).forEach((span) => {
        expect(ALLOWED_SPANS).toContain(span);
      });
    });

    stop();
  });

  it('changes nothing and sends nothing at the two edges of the arrangement', async () => {
    const served = serveLayout();
    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.moveBy('w1', -1);
    layout.moveBy('w3', 1);
    await flushPromises();

    expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);
    expect(served.requests).toHaveLength(0);

    stop();
  });

  it('sends the settled arrangement once more instead of one request per change', async () => {
    const followUp = serveLayout();
    const first = serveJson(layoutRoute(), { data: { id: DASHBOARD_ID } }, { held: true, once: true });

    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.moveBy('w1', 1);
    layout.setSpan('w3', 2);
    await vi.waitFor(() => expect(first.requests).toHaveLength(1));
    await flushPromises();

    expect(followUp.requests).toHaveLength(0);

    first.release();
    await vi.waitFor(() => expect(followUp.requests).toHaveLength(1));

    expect(first.requests).toHaveLength(1);
    expect(idsOf(followUp.lastRequest())).toEqual(['w2', 'w1', 'w3']);
    expect(arrangementOf(followUp.lastRequest()).find((entry) => entry.id === 'w3')?.column_span).toBe(2);

    stop();
  });
});

describe('useDashboardLayout — when the server refuses', () => {
  it('puts the previous order back and says so instead of diverging silently', async () => {
    serveServerError(layoutRoute(), {});

    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.moveBefore('w3', 'w1');

    expect(orderOf(layout)).toEqual(['w3', 'w1', 'w2']);

    await vi.waitFor(() => expect(toastError).toHaveBeenCalledTimes(1));

    expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);
    expect(layout.error.value).toBe(t(LAYOUT_ERROR_MESSAGE));
    expect(String(layout.error.value)).not.toMatch(ENGLISH_WORDS);

    stop();
  });

  it('puts the previous order back when the network drops the arrangement', async () => {
    serveNetworkError(layoutRoute());

    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.moveBefore('w3', 'w1');

    await vi.waitFor(() => expect(toastError).toHaveBeenCalledWith(t(LAYOUT_ERROR_MESSAGE)));

    expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);
    expect(layout.error.value).toBe(t(LAYOUT_ERROR_MESSAGE));

    stop();
  });

  it('states the reason the server gave for refusing the arrangement', async () => {
    serveJson(layoutRoute(), { message: 'Die Kachel gehört zu einem anderen Dashboard.' }, { status: 422 });

    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.moveBefore('w3', 'w1');
    await vi.waitFor(() => expect(toastError).toHaveBeenCalled());

    expect(layout.error.value).toBe('Die Kachel gehört zu einem anderen Dashboard.');
    expect(toastError).toHaveBeenCalledWith('Die Kachel gehört zu einem anderen Dashboard.');

    stop();
  });

  it('states the reason the server gave for refusing to remove a tile', async () => {
    serveJson(destroyRoute('w2'), { message: 'Sie dürfen diese Kachel nicht entfernen.' }, { status: 403 });

    const { layout, stop } = scoped(ref(threeWidgets()));

    await expect(layout.removeWidget('w2')).resolves.toBe(false);

    expect(layout.error.value).toBe('Sie dürfen diese Kachel nicht entfernen.');
    expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);

    stop();
  });

  it('keeps the tile and the German fallback when the network drops the removal', async () => {
    serveNetworkError(destroyRoute('w2'));

    const { layout, stop } = scoped(ref(threeWidgets()));

    await expect(layout.removeWidget('w2')).resolves.toBe(false);

    expect(layout.error.value).toBe(t(WIDGET_REMOVE_ERROR_MESSAGE));
    expect(toastError).toHaveBeenCalledWith(t(WIDGET_REMOVE_ERROR_MESSAGE));
    expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);

    stop();
  });

  it('states the reason a widget write was refused when no field error names it', async () => {
    serveJson(storeRoute(), { message: 'Der Bericht ist nicht mehr verfügbar.' }, { status: 422 });

    const { layout, stop } = scoped(ref(threeWidgets()));

    await expect(layout.addWidget({ report_id: 'gone' })).resolves.toBeNull();

    expect(layout.error.value).toBe('Der Bericht ist nicht mehr verfügbar.');
    expect(layout.validationErrors.value).toEqual({});

    stop();
  });

  it('binds the field errors of a refused widget write to their fields without a toast', async () => {
    serveValidationError(storeRoute(), {
      report_id: ['Bitte wählen Sie einen Bericht.'],
    });

    const { layout, stop } = scoped(ref(threeWidgets()));

    await expect(layout.addWidget({ report_id: '' })).resolves.toBeNull();

    expect(layout.validationErrors.value).toEqual({
      report_id: 'Bitte wählen Sie einen Bericht.',
    });
    expect(layout.error.value).toBeNull();
    expect(toastError).not.toHaveBeenCalled();
    expect(orderOf(layout)).toEqual(['w1', 'w2', 'w3']);

    stop();
  });

  it('keeps the German fallback when the network drops a widget write', async () => {
    serveNetworkError(storeRoute());

    const { layout, stop } = scoped(ref(threeWidgets()));

    await expect(layout.addWidget({ report_id: 'r-1' })).resolves.toBeNull();

    expect(layout.error.value).toBe(t(WIDGET_CREATE_ERROR_MESSAGE));
    expect(toastError).toHaveBeenCalledWith(t(WIDGET_CREATE_ERROR_MESSAGE));

    stop();
  });
});

describe('useDashboardLayout — announcing a move', () => {
  it('announces the move in German and names the moved tile', async () => {
    const served = serveLayout();

    const { layout, stop } = scoped(ref(threeWidgets()));

    expect(layout.announcement.value).toBe('');

    layout.moveBy('w1', 1);
    await nextTick();
    await nextTick();

    expect(layout.announcement.value).toBe('„Erste Kachel“ steht jetzt an Position 2 von 3.');
    expect(layout.announcement.value).not.toMatch(ENGLISH_WORDS);

    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    stop();
  });
});

describe('useDashboardLayout — the server stays the source of truth', () => {
  it('adopts a freshly delivered arrangement without writing it back', async () => {
    const served = serveLayout();
    const source = ref(threeWidgets());
    const { layout, stop } = scoped(source);

    source.value = [
      widget({ id: 'w9', title: 'Neue Kachel', column_span: 2 }),
      widget({ id: 'w1', title: 'Erste Kachel', column_span: 1 }),
    ];
    await flushPromises();

    expect(orderOf(layout)).toEqual(['w9', 'w1']);
    expect(spanOf(layout, 'w9')).toBe(2);
    expect(served.requests).toHaveLength(0);

    stop();
  });
});

describe('useDashboardLayout — adding and removing a widget', () => {
  it('never rearranges while adding or removing, but does while moving', async () => {
    const layoutServed = serveLayout();
    const created = serveJson(storeRoute(), { data: widget({ id: 'w4', title: 'Vierte Kachel' }) }, { status: 201 });
    const removed = serveEmpty(destroyRoute('w2'));

    const { layout, stop } = scoped(ref(threeWidgets()));

    await layout.addWidget({ report_id: 'r-1', chart_type: 'metric' });
    await layout.removeWidget('w2');
    await flushPromises();

    expect(created.requests).toHaveLength(1);
    expect(created.lastRequest()?.method).toBe('POST');
    expect(created.lastRequest()?.body).toEqual({
      report_id: 'r-1',
      chart_type: 'metric',
    });
    expect(removed.requests).toHaveLength(1);
    expect(removed.lastRequest()?.method).toBe('DELETE');
    expect(layoutServed.requests).toHaveLength(0);
    expect(orderOf(layout)).toEqual(['w1', 'w3', 'w4']);

    layout.moveBy('w1', 1);
    await vi.waitFor(() => expect(layoutServed.requests).toHaveLength(1));

    stop();
  });

  it('replaces the tile with the one the server stored', async () => {
    const served = serveJson(updateRoute('w2'), {
      data: widget({ id: 'w2', title: 'Umbenannt', column_span: 2 }),
    });

    const { layout, stop } = scoped(ref(threeWidgets()));

    const updated = await layout.updateWidget('w2', { title: 'Umbenannt' });

    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/${ACTIVE_TEAM}/dashboards/${DASHBOARD_ID}/widgets/w2`);
    expect(served.lastRequest()?.body).toEqual({ title: 'Umbenannt' });
    expect(updated?.title).toBe('Umbenannt');
    expect(layout.widgets.value.find((entry) => entry.id === 'w2')?.title).toBe('Umbenannt');
    expect(layout.error.value).toBeNull();

    stop();
  });
});

describe('useDashboardLayout — tearing the scope down', () => {
  it('aborts the arrangement in flight and stops touching its state afterwards', async () => {
    const served = serveServerError(layoutRoute(), { message: 'too late' }, { held: true });

    const { layout, stop } = scoped(ref(threeWidgets()));

    layout.moveBefore('w3', 'w1');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    stop();

    expect(served.lastRequest()?.signal.aborted).toBe(true);

    served.release();
    await flushPromises();

    expect(layout.error.value).toBeNull();
    expect(toastError).not.toHaveBeenCalled();
  });
});
