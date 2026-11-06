import type { ComputedRef, Ref } from 'vue';
import { computed, onScopeDispose, ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    index as presetIndexAction,
    store as presetStoreAction,
} from '@/actions/App/Http/Controllers/Export/ExportFieldPresetsController';
import {
    download as downloadAction,
    status as statusAction,
    store as storeAction,
} from '@/actions/App/Http/Controllers/Export/ExportsController';
import type { SelectionSortEntry } from '@/composables/useRecordSelection';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { AGING_COLUMN_IDS } from '@/types/aging';
import type { FieldDefinition } from '@/types/fields';

const REQUEST_TIMEOUT_MS = 15000;

const POLL_INTERVAL_MS = 1500;

const MAX_CONSECUTIVE_FAILURES = 5;

const START_ERROR_MESSAGE =
    'Der Export konnte nicht gestartet werden. Bitte versuchen Sie es erneut.';

const PROGRESS_ERROR_MESSAGE =
    'Der Fortschritt des Exports konnte nicht abgerufen werden.';

const PRESET_LOAD_ERROR_MESSAGE =
    'Die Feld-Presets konnten nicht geladen werden.';

const PRESET_SAVE_ERROR_MESSAGE =
    'Das Feld-Preset konnte nicht gespeichert werden.';

export type ExportFormat = 'csv' | 'xlsx' | 'json';

export interface ExportFieldOption {
    key: string;
    label: string;
}

export const EXPORT_IDENTITY_FIELDS: readonly ExportFieldOption[] = [
    { key: 'external_reference_id', label: 'Externe Referenz-ID' },
    { key: 'record_number', label: 'Datensatznummer' },
];

const NON_EXPORTABLE_FIELD_KEYS: readonly string[] = [
    AGING_COLUMN_IDS.age,
    AGING_COLUMN_IDS.stage,
];

export function exportableFieldOptions(
    fields: readonly FieldDefinition[],
): ExportFieldOption[] {
    return [
        ...EXPORT_IDENTITY_FIELDS,
        ...fields
            .filter((field) => !NON_EXPORTABLE_FIELD_KEYS.includes(field.key))
            .map((field) => ({ key: field.key, label: field.label })),
    ];
}

export type ExportScopeMode = 'view' | 'segment' | 'whole-type';

export interface ExportScope {
    mode: ExportScopeMode;
    filterModel?: Record<string, unknown>;
    search?: string | null;
    sortModel?: readonly SelectionSortEntry[];
    segmentId?: string;
}

export interface ExportStartPayload {
    format: ExportFormat;
    scope: ExportScope;
    fields: readonly string[];
}

export interface ExportContext {
    objectType: string;
}

export interface ExportFieldPreset {
    id: string;
    name: string;
    fields: string[];
}

interface ExportPresetEnvelope {
    data: ExportFieldPreset;
}

interface ExportPresetListEnvelope {
    data: ExportFieldPreset[];
}

export type ExportStatus =
    | 'idle'
    | 'polling'
    | 'finished'
    | 'failed'
    | 'cancelled';

interface ExportStoreResponse {
    batchId: string;
    exportJobId: string;
}

interface ExportStatusResponse {
    batchId: string;
    progress?: number;
    processedJobs?: number;
    totalJobs?: number;
    failedJobs?: number;
    finished?: boolean;
    cancelled?: boolean;
}

export interface UseExportReturn {
    start: (payload: ExportStartPayload, context: ExportContext) => void;
    stop: () => void;
    reset: () => void;
    loadPresets: (objectType: string) => Promise<void>;
    savePreset: (
        objectType: string,
        name: string,
        fields: readonly string[],
    ) => Promise<ExportFieldPreset | null>;
    presets: Ref<ExportFieldPreset[]>;
    status: Ref<ExportStatus>;
    progress: Ref<number>;
    processedJobs: Ref<number>;
    totalJobs: Ref<number>;
    failedJobs: Ref<number>;
    downloadUrl: Ref<string | null>;
    active: ComputedRef<boolean>;
}

export function useExport(): UseExportReturn {
    const status = ref<ExportStatus>('idle');
    const progress = ref<number>(0);
    const processedJobs = ref<number>(0);
    const totalJobs = ref<number>(0);
    const failedJobs = ref<number>(0);
    const downloadUrl = ref<string | null>(null);
    const presets = ref<ExportFieldPreset[]>([]);
    const active = computed<boolean>(() => status.value === 'polling');

    let timer: ReturnType<typeof setTimeout> | null = null;
    let currentBatchId: string | null = null;
    let currentExportJobId: string | null = null;
    let currentObjectType: string | null = null;
    let consecutiveFailures = 0;

    const clearTimer = (): void => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const request = async (
        url: string,
        init: RequestInit,
    ): Promise<Response> => {
        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        try {
            return await fetch(url, {
                credentials: 'same-origin',
                signal: controller.signal,
                ...init,
            });
        } finally {
            clearTimeout(timeout);
        }
    };

    const finish = (data: ExportStatusResponse): void => {
        clearTimer();

        if (data.cancelled === true) {
            status.value = 'cancelled';
            toast.info('Export abgebrochen.');

            return;
        }

        status.value = 'finished';

        if (currentObjectType !== null && currentExportJobId !== null) {
            downloadUrl.value = downloadAction.url({
                objectType: currentObjectType,
                exportJob: currentExportJobId,
            });
        }

        toast.success('Export erfolgreich abgeschlossen.');
    };

    const poll = async (batchId: string): Promise<void> => {
        if (batchId !== currentBatchId || currentObjectType === null) {
            return;
        }

        try {
            const response = await request(
                statusAction.url({
                    objectType: currentObjectType,
                    batch: batchId,
                }),
                { method: 'GET', headers: buildHeaders() },
            );

            if (!response.ok) {
                throw new Error(
                    `Export status endpoint responded with status ${response.status}`,
                );
            }

            const data = (await response.json()) as ExportStatusResponse;

            if (batchId !== currentBatchId) {
                return;
            }

            consecutiveFailures = 0;
            progress.value = data.progress ?? 0;
            processedJobs.value = data.processedJobs ?? 0;
            totalJobs.value = data.totalJobs ?? 0;
            failedJobs.value = data.failedJobs ?? 0;

            if (data.finished === true || data.cancelled === true) {
                finish(data);

                return;
            }

            timer = setTimeout(() => void poll(batchId), POLL_INTERVAL_MS);
        } catch {
            if (batchId !== currentBatchId) {
                return;
            }

            consecutiveFailures += 1;

            if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
                clearTimer();
                status.value = 'failed';
                toast.error(PROGRESS_ERROR_MESSAGE);

                return;
            }

            timer = setTimeout(() => void poll(batchId), POLL_INTERVAL_MS);
        }
    };

    const run = async (
        payload: ExportStartPayload,
        objectType: string,
    ): Promise<void> => {
        try {
            const response = await request(storeAction.url({ objectType }), {
                method: 'POST',
                headers: buildHeaders({ hasBody: true }),
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                throw new Error(
                    `Export store endpoint responded with status ${response.status}`,
                );
            }

            const data = (await response.json()) as ExportStoreResponse;

            currentBatchId = data.batchId;
            currentExportJobId = data.exportJobId;

            void poll(data.batchId);
        } catch {
            clearTimer();
            currentBatchId = null;
            currentExportJobId = null;
            status.value = 'failed';
            toast.error(START_ERROR_MESSAGE);
        }
    };

    const resetState = (): void => {
        clearTimer();
        currentBatchId = null;
        currentExportJobId = null;
        consecutiveFailures = 0;
        progress.value = 0;
        processedJobs.value = 0;
        totalJobs.value = 0;
        failedJobs.value = 0;
        downloadUrl.value = null;
    };

    const start = (
        payload: ExportStartPayload,
        context: ExportContext,
    ): void => {
        resetState();
        currentObjectType = context.objectType;
        status.value = 'polling';

        void run(payload, context.objectType);
    };

    const stop = (): void => {
        clearTimer();
        currentBatchId = null;
        currentExportJobId = null;
    };

    const reset = (): void => {
        resetState();
        currentObjectType = null;
        status.value = 'idle';
    };

    const loadPresets = async (objectType: string): Promise<void> => {
        try {
            const response = await request(
                presetIndexAction.url({ objectType }),
                {
                    method: 'GET',
                    headers: buildHeaders(),
                },
            );

            if (!response.ok) {
                throw new Error(
                    `Export preset index endpoint responded with status ${response.status}`,
                );
            }

            const data = (await response.json()) as ExportPresetListEnvelope;

            presets.value = data.data ?? [];
        } catch {
            toast.error(PRESET_LOAD_ERROR_MESSAGE);
        }
    };

    const savePreset = async (
        objectType: string,
        name: string,
        fields: readonly string[],
    ): Promise<ExportFieldPreset | null> => {
        try {
            const response = await request(
                presetStoreAction.url({ objectType }),
                {
                    method: 'POST',
                    headers: buildHeaders({ hasBody: true }),
                    body: JSON.stringify({ name, fields }),
                },
            );

            if (!response.ok) {
                throw new Error(
                    `Export preset store endpoint responded with status ${response.status}`,
                );
            }

            const data = (await response.json()) as ExportPresetEnvelope;

            presets.value = [...presets.value, data.data];
            toast.success('Feld-Preset gespeichert.');

            return data.data;
        } catch {
            toast.error(PRESET_SAVE_ERROR_MESSAGE);

            return null;
        }
    };

    onScopeDispose(() => {
        currentBatchId = null;
        currentExportJobId = null;
        currentObjectType = null;
        clearTimer();
    });

    return {
        start,
        stop,
        reset,
        loadPresets,
        savePreset,
        presets,
        status,
        progress,
        processedJobs,
        totalJobs,
        failedJobs,
        downloadUrl,
        active,
    };
}
