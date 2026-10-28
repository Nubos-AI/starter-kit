import type { Ref } from 'vue';
import { onScopeDispose, ref } from 'vue';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { preview as previewRoute } from '@/routes/reports';
import type { ReportRefusal, ReportResult } from '@/types/reports';

const REQUEST_TIMEOUT_MS = 15000;

const REFUSED_STATUS = 422;

export type ReportPreviewState = 'idle' | 'loading' | 'settled';

export interface UseReportPreviewReturn {
    state: Ref<ReportPreviewState>;
    result: Ref<ReportResult | null>;
    refusal: Ref<ReportRefusal | null>;
    run: (payload: Record<string, unknown>) => Promise<void>;
    reset: () => void;
}

function readEnvelope(body: unknown): ReportResult | null {
    if (body === null || typeof body !== 'object' || !('data' in body)) {
        return null;
    }

    const data = body.data;

    return data === null || typeof data !== 'object' || Array.isArray(data)
        ? null
        : (data as ReportResult);
}

function readReason(body: unknown): string {
    if (body === null || typeof body !== 'object' || !('reason' in body)) {
        return '';
    }

    return typeof body.reason === 'string' ? body.reason : '';
}

async function readJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

export function useReportPreview(): UseReportPreviewReturn {
    const state = ref<ReportPreviewState>('idle');
    const result = ref<ReportResult | null>(null);
    const refusal = ref<ReportRefusal | null>(null);

    let controller: AbortController | null = null;
    let sequence = 0;
    let disposed = false;

    function abortPending(): void {
        controller?.abort();
        controller = null;
    }

    onScopeDispose(() => {
        disposed = true;
        abortPending();
    });

    async function run(payload: Record<string, unknown>): Promise<void> {
        abortPending();

        sequence += 1;

        const current = sequence;
        const active = new AbortController();

        controller = active;
        state.value = 'loading';
        result.value = null;
        refusal.value = null;

        const timeout = setTimeout(() => active.abort(), REQUEST_TIMEOUT_MS);

        try {
            const response = await fetch(previewRoute.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: true }),
                body: JSON.stringify(payload),
                signal: active.signal,
            });

            const body = await readJson(response);

            if (disposed || current !== sequence) {
                return;
            }

            const data = response.ok ? readEnvelope(body) : null;

            result.value = data;
            refusal.value =
                data !== null
                    ? null
                    : {
                          reason:
                              response.status === REFUSED_STATUS
                                  ? readReason(body)
                                  : '',
                      };
        } catch {
            if (disposed || current !== sequence) {
                return;
            }

            result.value = null;
            refusal.value = { reason: '' };
        } finally {
            clearTimeout(timeout);

            if (!disposed && current === sequence) {
                state.value = 'settled';
                controller = null;
            }
        }
    }

    function reset(): void {
        abortPending();

        sequence += 1;
        state.value = 'idle';
        result.value = null;
        refusal.value = null;
    }

    return { state, result, refusal, run, reset };
}
