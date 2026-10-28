import type { Ref } from 'vue';
import { nextTick, onScopeDispose, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DashboardWidgetsController from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetsController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import {
    readErrorBody,
    readErrorReason,
    toFieldErrors,
} from '@/lib/errorResponse';
import type {
    DashboardColumnSpan,
    DashboardWidgetMeta,
} from '@/types/dashboards';
import { clampColumnSpan, widgetTitle } from '@/types/dashboards';

const REQUEST_TIMEOUT_MS = 15000;

export const LAYOUT_ERROR_MESSAGE =
    'Die Anordnung konnte nicht gespeichert werden. Die vorherige Reihenfolge wurde wiederhergestellt.';

export const WIDGET_CREATE_ERROR_MESSAGE =
    'Die Kachel konnte nicht angelegt werden. Bitte versuchen Sie es erneut.';

export const WIDGET_UPDATE_ERROR_MESSAGE =
    'Die Kachel konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

export const WIDGET_REMOVE_ERROR_MESSAGE =
    'Die Kachel konnte nicht entfernt werden. Bitte versuchen Sie es erneut.';

const REFUSED_STATUS = 422;

export interface UseDashboardLayoutReturn {
    widgets: Ref<DashboardWidgetMeta[]>;
    announcement: Ref<string>;
    error: Ref<string | null>;
    validationErrors: Ref<Record<string, string>>;
    moveBefore: (widgetId: string, targetWidgetId: string) => void;
    moveBy: (widgetId: string, offset: -1 | 1) => void;
    setSpan: (widgetId: string, span: DashboardColumnSpan) => void;
    addWidget: (
        payload: Record<string, unknown>,
        position?: number,
    ) => Promise<DashboardWidgetMeta | null>;
    updateWidget: (
        widgetId: string,
        payload: Record<string, unknown>,
    ) => Promise<DashboardWidgetMeta | null>;
    removeWidget: (widgetId: string) => Promise<boolean>;
}

function readWidget(body: unknown): DashboardWidgetMeta | null {
    if (body === null || typeof body !== 'object' || !('data' in body)) {
        return null;
    }

    const data = body.data;

    return data === null || typeof data !== 'object' || Array.isArray(data)
        ? null
        : (data as DashboardWidgetMeta);
}

async function readJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

export function useDashboardLayout(
    dashboardId: string,
    initial: () => DashboardWidgetMeta[],
): UseDashboardLayoutReturn {
    const widgets = ref<DashboardWidgetMeta[]>([...initial()]);
    const announcement = ref<string>('');
    const isSaving = ref<boolean>(false);
    const error = ref<string | null>(null);
    const validationErrors = ref<Record<string, string>>({});

    const controllers = new Set<AbortController>();

    let settled: DashboardWidgetMeta[] = [...widgets.value];
    let followUpPending = false;
    let disposed = false;

    onScopeDispose(() => {
        disposed = true;
        controllers.forEach((controller) => controller.abort());
        controllers.clear();
    });

    watch(initial, (next) => {
        widgets.value = [...next];
        settled = [...next];
    });

    async function request(
        url: string,
        method: string,
        payload?: Record<string, unknown>,
    ): Promise<Response | null> {
        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        controllers.add(controller);

        try {
            return await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: payload !== undefined }),
                body:
                    payload === undefined ? undefined : JSON.stringify(payload),
                signal: controller.signal,
            });
        } catch {
            return null;
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    }

    async function announce(message: string): Promise<void> {
        announcement.value = '';
        await nextTick();

        if (!disposed) {
            announcement.value = message;
        }
    }

    function positionMessage(widget: DashboardWidgetMeta): string {
        const position =
            widgets.value.findIndex((entry) => entry.id === widget.id) + 1;

        return `„${widgetTitle(widget.title)}“ steht jetzt an Position ${position} von ${widgets.value.length}.`;
    }

    function arrangement(): Array<{
        id: string;
        column_span: DashboardColumnSpan;
    }> {
        return widgets.value.map((entry) => ({
            id: entry.id,
            column_span: entry.column_span,
        }));
    }

    async function persist(): Promise<void> {
        if (isSaving.value) {
            followUpPending = true;

            return;
        }

        isSaving.value = true;

        const response = await request(
            DashboardWidgetsController.updateLayout.url({
                dashboard: dashboardId,
            }),
            'PUT',
            { widgets: arrangement() },
        );

        if (disposed) {
            return;
        }

        isSaving.value = false;

        if (response === null || !response.ok) {
            const reason =
                response === null
                    ? LAYOUT_ERROR_MESSAGE
                    : await readErrorReason(response, LAYOUT_ERROR_MESSAGE);

            followUpPending = false;
            widgets.value = [...settled];
            error.value = reason;
            toast.error(reason);

            return;
        }

        error.value = null;
        settled = [...widgets.value];

        if (followUpPending) {
            followUpPending = false;
            await persist();
        }
    }

    function moveBefore(widgetId: string, targetWidgetId: string): void {
        if (widgetId === targetWidgetId) {
            return;
        }

        const next = [...widgets.value];
        const from = next.findIndex((entry) => entry.id === widgetId);

        if (from === -1) {
            return;
        }

        const [moved] = next.splice(from, 1);
        const to = next.findIndex((entry) => entry.id === targetWidgetId);

        if (to === -1) {
            return;
        }

        next.splice(to, 0, moved);
        widgets.value = next;

        void announce(positionMessage(moved));
        void persist();
    }

    function moveBy(widgetId: string, offset: -1 | 1): void {
        const from = widgets.value.findIndex((entry) => entry.id === widgetId);
        const to = from + offset;

        if (from === -1 || to < 0 || to >= widgets.value.length) {
            return;
        }

        const next = [...widgets.value];
        const [moved] = next.splice(from, 1);

        next.splice(to, 0, moved);
        widgets.value = next;

        void announce(positionMessage(moved));
        void persist();
    }

    function setSpan(widgetId: string, span: DashboardColumnSpan): void {
        const clamped = clampColumnSpan(span);

        widgets.value = widgets.value.map((entry) =>
            entry.id === widgetId ? { ...entry, column_span: clamped } : entry,
        );

        void persist();
    }

    async function writeWidget(
        url: string,
        method: string,
        payload: Record<string, unknown>,
        failure: string,
    ): Promise<DashboardWidgetMeta | null> {
        const response = await request(url, method, payload);

        if (disposed) {
            return null;
        }

        const body = response === null ? null : await readJson(response);
        const widget =
            response !== null && response.ok ? readWidget(body) : null;

        if (widget === null) {
            const refusal = readErrorBody(body);
            const fields =
                response?.status === REFUSED_STATUS
                    ? toFieldErrors(refusal.errors)
                    : {};

            validationErrors.value = fields;

            if (Object.keys(fields).length === 0) {
                const reason = refusal.message ?? failure;

                error.value = reason;
                toast.error(reason);
            }

            return null;
        }

        error.value = null;
        validationErrors.value = {};

        return widget;
    }

    async function addWidget(
        payload: Record<string, unknown>,
        position?: number,
    ): Promise<DashboardWidgetMeta | null> {
        const created = await writeWidget(
            DashboardWidgetsController.store.url({ dashboard: dashboardId }),
            'POST',
            payload,
            WIDGET_CREATE_ERROR_MESSAGE,
        );

        if (created === null) {
            return null;
        }

        const appended = [...widgets.value, created];
        const index =
            position === undefined
                ? appended.length - 1
                : Math.min(Math.max(position, 0), appended.length - 1);

        settled = appended;

        if (index === appended.length - 1) {
            widgets.value = appended;

            return created;
        }

        const next = [...widgets.value];

        next.splice(index, 0, created);
        widgets.value = next;

        void persist();

        return created;
    }

    async function updateWidget(
        widgetId: string,
        payload: Record<string, unknown>,
    ): Promise<DashboardWidgetMeta | null> {
        const updated = await writeWidget(
            DashboardWidgetsController.update.url({
                dashboard: dashboardId,
                widget: widgetId,
            }),
            'PUT',
            payload,
            WIDGET_UPDATE_ERROR_MESSAGE,
        );

        if (updated === null) {
            return null;
        }

        widgets.value = widgets.value.map((entry) =>
            entry.id === widgetId ? updated : entry,
        );
        settled = [...widgets.value];

        return updated;
    }

    async function removeWidget(widgetId: string): Promise<boolean> {
        const response = await request(
            DashboardWidgetsController.destroy.url({
                dashboard: dashboardId,
                widget: widgetId,
            }),
            'DELETE',
        );

        if (disposed) {
            return false;
        }

        if (response === null || !response.ok) {
            const reason =
                response === null
                    ? WIDGET_REMOVE_ERROR_MESSAGE
                    : await readErrorReason(
                          response,
                          WIDGET_REMOVE_ERROR_MESSAGE,
                      );

            error.value = reason;
            toast.error(reason);

            return false;
        }

        error.value = null;
        widgets.value = widgets.value.filter((entry) => entry.id !== widgetId);
        settled = [...widgets.value];

        return true;
    }

    return {
        widgets,
        announcement,
        error,
        validationErrors,
        moveBefore,
        moveBy,
        setSpan,
        addWidget,
        updateWidget,
        removeWidget,
    };
}
