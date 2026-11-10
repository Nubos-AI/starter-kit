import type { Ref } from 'vue';
import { ref } from 'vue';
import {
    cancel as cancelRoute,
    show,
} from '@/actions/App/Http/Controllers/Formulas/FormulaBackfillsController';
import { usePollingLoop } from '@/composables/usePollingLoop';
import { buildHeaders } from '@/composables/useRequestHeaders';
import type { BackfillStatus } from '@/types/formulas';

export interface FormulaBackfillRunState {
    id: string;
    status: BackfillStatus;
    totalCount: number;
    processedCount: number;
    errorCount: number;
}

export interface UseFormulaBackfillOptions {
    onCompleted?: () => void;
}

export interface UseFormulaBackfillReturn {
    run: Ref<FormulaBackfillRunState | null>;
    isCancelling: Ref<boolean>;
    cancel: () => Promise<void>;
}

interface FormulaBackfillPayload {
    id: string;
    status: BackfillStatus;
    totalCount: number;
    processedCount: number;
    errorCount: number;
    finishedAt: string | null;
}

interface FormulaBackfillResponse {
    run: FormulaBackfillPayload | null;
}

const terminalStatuses: ReadonlySet<BackfillStatus> = new Set<BackfillStatus>([
    'completed',
    'cancelled',
    'failed',
]);

function isTerminalStatus(status: BackfillStatus): boolean {
    return terminalStatuses.has(status);
}

function toRunState(
    payload: FormulaBackfillPayload | null,
): FormulaBackfillRunState | null {
    if (payload === null) {
        return null;
    }

    return {
        id: payload.id,
        status: payload.status,
        totalCount: payload.totalCount,
        processedCount: payload.processedCount,
        errorCount: payload.errorCount,
    };
}

export function useFormulaBackfill(
    objectTypeSlug: string,
    options: UseFormulaBackfillOptions = {},
): UseFormulaBackfillReturn {
    const run = ref<FormulaBackfillRunState | null>(null);
    const isCancelling = ref<boolean>(false);

    const loop = usePollingLoop();

    const poll = async (): Promise<void> => {
        try {
            const response = await loop.request(
                show.url({ objectType: objectTypeSlug }),
                {
                    method: 'GET',
                    headers: buildHeaders(),
                },
            );

            if (response.status === 403 || response.status === 404) {
                loop.clear();
                run.value = null;

                return;
            }

            if (!response.ok) {
                throw new Error(
                    `Formula backfill status endpoint responded with status ${response.status}`,
                );
            }

            const data = (await response.json()) as FormulaBackfillResponse;

            loop.resetFailures();

            const current = toRunState(data.run);
            run.value = current;

            if (current === null) {
                loop.clear();

                return;
            }

            if (isTerminalStatus(current.status)) {
                loop.clear();

                if (current.status === 'completed') {
                    options.onCompleted?.();
                }

                return;
            }

            loop.schedule(() => void poll());
        } catch {
            if (loop.registerFailure()) {
                return;
            }

            loop.schedule(() => void poll());
        }
    };

    const cancel = async (): Promise<void> => {
        const current = run.value;

        if (current === null || isCancelling.value) {
            return;
        }

        isCancelling.value = true;

        try {
            const response = await loop.request(
                cancelRoute.url({ backfillRun: current.id }),
                {
                    method: 'POST',
                    headers: buildHeaders(),
                },
            );

            if (!response.ok) {
                return;
            }

            const data = (await response.json()) as FormulaBackfillResponse;

            loop.clear();
            run.value = toRunState(data.run);
        } catch {
        } finally {
            isCancelling.value = false;
        }
    };

    loop.start(() => void poll());

    return { run, isCancelling, cancel };
}
