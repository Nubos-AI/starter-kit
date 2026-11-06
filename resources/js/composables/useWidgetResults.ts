import type { Ref } from 'vue';
import { onScopeDispose, ref } from 'vue';
import { toast } from 'vue-sonner';
import DashboardWidgetResultsController from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetResultsController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import type { DashboardWidgetTile } from '@/types/dashboards';

const REQUEST_TIMEOUT_MS = 15000;

export const RESULTS_ERROR_MESSAGE =
    'Die Zahlen dieses Dashboards konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

export interface UseWidgetResultsReturn {
    tiles: Ref<Record<string, DashboardWidgetTile>>;
    pending: Ref<Set<string>>;
    isRefreshingAll: Ref<boolean>;
    error: Ref<string | null>;
    refreshAll: () => Promise<void>;
    refreshOne: (widgetId: string) => Promise<void>;
}

function readCollection(body: unknown): DashboardWidgetTile[] | null {
    if (body === null || typeof body !== 'object' || !('data' in body)) {
        return null;
    }

    return Array.isArray(body.data)
        ? (body.data as DashboardWidgetTile[])
        : null;
}

function readTile(body: unknown): DashboardWidgetTile | null {
    if (body === null || typeof body !== 'object' || !('data' in body)) {
        return null;
    }

    const data = body.data;

    return data === null || typeof data !== 'object' || Array.isArray(data)
        ? null
        : (data as DashboardWidgetTile);
}

export function useWidgetResults(dashboardId: string): UseWidgetResultsReturn {
    const tiles = ref<Record<string, DashboardWidgetTile>>({});
    const pending = ref<Set<string>>(new Set());
    const isRefreshingAll = ref<boolean>(false);
    const error = ref<string | null>(null);

    const controllers = new Set<AbortController>();

    let sequence = 0;
    let disposed = false;

    onScopeDispose(() => {
        disposed = true;
        controllers.forEach((controller) => controller.abort());
        controllers.clear();
    });

    async function request(url: string): Promise<unknown> {
        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        controllers.add(controller);

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: true }),
                body: JSON.stringify({}),
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(
                    `The widget results endpoint responded with status ${response.status}`,
                );
            }

            return await response.json();
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    }

    function fail(): void {
        error.value = RESULTS_ERROR_MESSAGE;
        toast.error(RESULTS_ERROR_MESSAGE);
    }

    async function refreshAll(): Promise<void> {
        if (isRefreshingAll.value) {
            return;
        }

        isRefreshingAll.value = true;
        sequence += 1;

        const current = sequence;

        try {
            const delivered = readCollection(
                await request(
                    DashboardWidgetResultsController.index.url({
                        dashboard: dashboardId,
                    }),
                ),
            );

            if (disposed || current !== sequence) {
                return;
            }

            if (delivered === null) {
                throw new Error('The widget results carried no collection');
            }

            tiles.value = Object.fromEntries(
                delivered.map((tile) => [tile.widget_id, tile]),
            );
            error.value = null;
        } catch {
            if (disposed) {
                return;
            }

            fail();
        } finally {
            if (!disposed) {
                isRefreshingAll.value = false;
            }
        }
    }

    async function refreshOne(widgetId: string): Promise<void> {
        if (pending.value.has(widgetId)) {
            return;
        }

        pending.value.add(widgetId);

        try {
            const delivered = readTile(
                await request(
                    DashboardWidgetResultsController.show.url({
                        dashboard: dashboardId,
                        widget: widgetId,
                    }),
                ),
            );

            if (disposed) {
                return;
            }

            if (delivered === null) {
                throw new Error('The widget result carried no tile');
            }

            sequence += 1;
            tiles.value = { ...tiles.value, [delivered.widget_id]: delivered };
            error.value = null;
        } catch {
            if (disposed) {
                return;
            }

            fail();
        } finally {
            pending.value.delete(widgetId);
        }
    }

    return { tiles, pending, isRefreshingAll, error, refreshAll, refreshOne };
}
