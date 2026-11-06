import type { IDatasource, IGetRowsParams } from 'ag-grid-community';
import type { MaybeRefOrGetter, Ref } from 'vue';
import { ref, toValue } from 'vue';
import RecordGridsController from '@/actions/App/Http/Controllers/Engine/RecordGridsController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';
import type {
    GridGetRowsRequest,
    GridGetRowsResponse,
    InitialRecordGrid,
} from '@/types/grid';
import type { ReportDrillDownParams } from '@/types/reports';

export interface UseRecordDatasourceReturn {
    datasource: IDatasource;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    hierarchyNotice: Ref<string | null>;
}

const REQUEST_TIMEOUT_MS = 20000;

const SESSION_EXPIRED_STATUS = 419;

const LOAD_ERROR_MESSAGE =
    'Die Datensätze konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const TIMEOUT_ERROR_MESSAGE =
    'Die Abfrage hat zu lange gedauert. Bitte schränken Sie Filter oder Suche ein.';

const SESSION_ERROR_MESSAGE =
    'Ihre Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.';

const HIERARCHY_CEILING_NOTICE =
    'Für diese Menge an Datensätzen ist die Hierarchie-Ansicht abgeschaltet. Die Liste ist nach Erstellung sortiert.';

export function useRecordDatasource(
    slug: string,
    segmentId?: MaybeRefOrGetter<string | null>,
    drillDown?: MaybeRefOrGetter<ReportDrillDownParams | null>,
    hierarchy?: MaybeRefOrGetter<boolean>,
    search?: MaybeRefOrGetter<string | null>,
    initialGrid?: InitialRecordGrid | null,
    onInitialConsumed?: () => void,
): UseRecordDatasourceReturn {
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);
    const hierarchyNotice = ref<string | null>(null);
    const endpoint = RecordGridsController.grid.url({ objectType: slug });
    let initial = initialGrid ?? null;

    const deliver = (
        params: IGetRowsParams,
        request: GridGetRowsRequest,
        payload: GridGetRowsResponse,
    ): void => {
        error.value = null;
        hierarchyNotice.value =
            request.hierarchy === true &&
            payload.hierarchy?.applied === false &&
            payload.hierarchy.reason === 'too_many_rows'
                ? HIERARCHY_CEILING_NOTICE
                : null;
        params.successCallback(payload.rows, payload.lastRow ?? -1);
    };

    const failureReason = async (response: Response): Promise<string> => {
        if (response.status === SESSION_EXPIRED_STATUS) {
            return SESSION_ERROR_MESSAGE;
        }

        return readErrorReason(response, LOAD_ERROR_MESSAGE);
    };

    const getRows = async (params: IGetRowsParams): Promise<void> => {
        const isInitialBlock = params.startRow === 0;

        if (isInitialBlock) {
            loading.value = true;
        }

        const drill = toValue(drillDown) ?? null;

        const request: GridGetRowsRequest = {
            startRow: params.startRow,
            endRow: params.endRow,
            sortModel: params.sortModel,
            filterModel: params.filterModel as Record<string, unknown>,
            search: toValue(search) ?? null,
            segment: toValue(segmentId) ?? null,
            report: drill?.report ?? null,
            dashboard: drill?.dashboard ?? null,
            widget: drill?.widget ?? null,
            group: drill?.group ?? null,
            series: drill?.series ?? null,
            hierarchy: toValue(hierarchy) === true,
        };

        const firstBlock = initial;
        initial = null;

        if (firstBlock !== null) {
            onInitialConsumed?.();

            if (requestKey(firstBlock.request) === requestKey(request)) {
                loading.value = false;
                deliver(params, request, firstBlock.response);

                return;
            }
        }

        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        let payload: GridGetRowsResponse | null = null;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: true }),
                body: JSON.stringify(request),
                signal: controller.signal,
            });

            if (response.ok) {
                payload = (await response.json()) as GridGetRowsResponse;
            } else {
                error.value = await failureReason(response);
                params.failCallback();
            }
        } catch (cause: unknown) {
            error.value = isAbort(cause, controller.signal)
                ? TIMEOUT_ERROR_MESSAGE
                : LOAD_ERROR_MESSAGE;
            params.failCallback();
        } finally {
            clearTimeout(timeout);

            if (isInitialBlock) {
                loading.value = false;
            }
        }

        if (payload !== null) {
            deliver(params, request, payload);
        }
    };

    return {
        datasource: { getRows },
        loading,
        error,
        hierarchyNotice,
    };
}

function requestKey(request: GridGetRowsRequest): string {
    return JSON.stringify([
        request.startRow,
        request.endRow,
        request.sortModel.map(({ colId, sort }) => [colId, sort]),
        canonicalValue(Object.fromEntries(Object.entries(request.filterModel))),
        request.search ?? null,
        request.segment ?? null,
        request.report ?? null,
        request.dashboard ?? null,
        request.widget ?? null,
        request.group ?? null,
        request.series ?? null,
        request.hierarchy === true,
    ]);
}

function canonicalValue(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map(canonicalValue);
    }

    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value)
                .sort(([first], [second]) => first.localeCompare(second))
                .map(([key, entry]) => [key, canonicalValue(entry)]),
        );
    }

    return value;
}

function isAbort(cause: unknown, signal: AbortSignal): boolean {
    if (signal.aborted) {
        return true;
    }

    return cause instanceof DOMException && cause.name === 'AbortError';
}
