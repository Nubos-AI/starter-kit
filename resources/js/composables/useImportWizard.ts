import type { Ref } from 'vue';
import { computed, getCurrentScope, onScopeDispose, ref, watch } from 'vue';
import {
    index as presetIndexAction,
    store as presetStoreAction,
} from '@/actions/App/Http/Controllers/Import/ImportMappingPresetsController';
import { preview as previewAction } from '@/actions/App/Http/Controllers/Import/ImportPreviewsController';
import { store as uploadAction } from '@/actions/App/Http/Controllers/Import/ImportUploadsController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import type { FieldDefinition } from '@/types/fields';

const REQUEST_TIMEOUT_MS = 30000;

const UPLOAD_ERROR_MESSAGE =
    'Die Datei konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.';

const PREVIEW_ERROR_MESSAGE =
    'Die Vorschau konnte nicht ermittelt werden. Bitte versuchen Sie es erneut.';

const PRESET_ERROR_MESSAGE =
    'Die Vorlage konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

export interface ImportMappingTarget {
    value: string;
    label: string;
}

export const IMPORT_IDENTITY_TARGETS: readonly ImportMappingTarget[] = [
    { value: 'external_reference_id', label: 'Externe Referenz-ID' },
    { value: 'record_number', label: 'Datensatznummer' },
];

export type ImportStep = 'upload' | 'sheet' | 'mapping' | 'format' | 'dryRun';

export type ImportFileFormat = 'csv' | 'xlsx';

export type ImportDuplicateMode = 'skip' | 'upsert' | 'insert';

export type ImportMissingOptionMode = 'create' | 'error';

export type ImportColumnFormat =
    | 'text'
    | 'integer'
    | 'decimal'
    | 'money'
    | 'date'
    | 'datetime'
    | 'boolean'
    | 'single_select'
    | 'multi_select';

export const IMPORT_COLUMN_FORMAT_LABELS: Record<ImportColumnFormat, string> = {
    text: 'Text',
    integer: 'Ganzzahl',
    decimal: 'Dezimalzahl',
    money: 'Betrag',
    date: 'Datum',
    datetime: 'Datum und Uhrzeit',
    boolean: 'Ja/Nein',
    single_select: 'Einfachauswahl',
    multi_select: 'Mehrfachauswahl',
};

export interface UploadResult {
    path: string;
    format: ImportFileFormat;
    sheets: string[];
    headerSuggestion: string[];
    encoding?: string;
    delimiter?: string;
}

export interface MappingState {
    columns: Record<string, string>;
    formats: Record<string, ImportColumnFormat>;
}

export interface ImportFormatOverride {
    encoding?: string;
    delimiter?: string;
}

export interface DryRunSampleError {
    row: number;
    message: string;
}

export interface DryRunResult {
    new: number;
    updates: number;
    errors: number;
    duplicateDetection: boolean;
    sampleErrors: DryRunSampleError[];
}

export interface ImportMappingPreset {
    id: string;
    name: string;
    mapping: MappingState;
}

export interface UseImportWizardReturn {
    currentStep: Ref<ImportStep>;
    file: Ref<File | null>;
    uploadResult: Ref<UploadResult | null>;
    formatOverride: Ref<ImportFormatOverride>;
    sheet: Ref<string | null>;
    mapping: Ref<MappingState>;
    duplicateMode: Ref<ImportDuplicateMode>;
    missingOptionMode: Ref<ImportMissingOptionMode>;
    dryRunResult: Ref<DryRunResult | null>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    presets: Ref<ImportMappingPreset[]>;
    canStartImport: Ref<boolean>;
    upload: (uploadFile: File) => Promise<void>;
    setFormatOverride: (patch: ImportFormatOverride) => void;
    selectSheet: (name: string) => void;
    autoMatch: () => void;
    setMapping: (patch: Partial<MappingState>) => void;
    listPresets: () => Promise<void>;
    savePreset: (name: string) => Promise<void>;
    loadPreset: (preset: ImportMappingPreset) => void;
    runDryRun: () => Promise<void>;
    reset: () => void;
}

async function previewFailureMessage(response: Response): Promise<string> {
    if (response.status < 400 || response.status >= 500) {
        return PREVIEW_ERROR_MESSAGE;
    }

    try {
        const payload = (await response.json()) as { message?: unknown };

        return typeof payload.message === 'string' && payload.message !== ''
            ? payload.message
            : PREVIEW_ERROR_MESSAGE;
    } catch {
        return PREVIEW_ERROR_MESSAGE;
    }
}

function normalizeHeader(value: string): string {
    return value.toLowerCase().replace(/[\s_-]+/g, '');
}

export function autoMatch(
    headers: string[],
    fields: FieldDefinition[],
): Record<string, string> {
    const index = new Map<string, string>();

    for (const target of IMPORT_IDENTITY_TARGETS) {
        index.set(normalizeHeader(target.value), target.value);
        index.set(normalizeHeader(target.label), target.value);
    }

    for (const field of fields) {
        index.set(normalizeHeader(field.key), field.key);
        index.set(normalizeHeader(field.label), field.key);
    }

    const columns: Record<string, string> = {};

    for (const header of headers) {
        const match = index.get(normalizeHeader(header));

        if (match !== undefined) {
            columns[header] = match;
        }
    }

    return columns;
}

export function importMappingTargets(
    fields: FieldDefinition[],
): ImportMappingTarget[] {
    return [
        ...IMPORT_IDENTITY_TARGETS,
        ...fields.map((field) => ({ value: field.key, label: field.label })),
    ];
}

export function useImportWizard(
    objectType: string,
    fields: FieldDefinition[] = [],
): UseImportWizardReturn {
    const currentStep = ref<ImportStep>('upload');
    const file = ref<File | null>(null);
    const uploadResult = ref<UploadResult | null>(null);
    const formatOverride = ref<ImportFormatOverride>({});
    const sheet = ref<string | null>(null);
    const mapping = ref<MappingState>({ columns: {}, formats: {} });
    const duplicateMode = ref<ImportDuplicateMode>('skip');
    const missingOptionMode = ref<ImportMissingOptionMode>('error');
    const dryRunResult = ref<DryRunResult | null>(null);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);
    const presets = ref<ImportMappingPreset[]>([]);

    const controllers = new Set<AbortController>();

    const canStartImport = computed<boolean>(
        () => dryRunResult.value !== null && !loading.value,
    );

    const request = async (
        url: string,
        init: RequestInit,
    ): Promise<Response> => {
        const controller = new AbortController();
        controllers.add(controller);
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );
        const isForm = init.body instanceof FormData;

        try {
            return await fetch(url, {
                credentials: 'same-origin',
                headers: buildHeaders({
                    hasBody:
                        init.body !== undefined &&
                        init.body !== null &&
                        !isForm,
                }),
                signal: controller.signal,
                ...init,
            });
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    };

    const nextStepAfterUpload = (result: UploadResult): ImportStep =>
        result.format === 'xlsx' && result.sheets.length > 1
            ? 'sheet'
            : 'mapping';

    const upload = async (uploadFile: File): Promise<void> => {
        file.value = uploadFile;
        loading.value = true;

        try {
            const body = new FormData();
            body.append('file', uploadFile);

            const response = await request(uploadAction.url({ objectType }), {
                method: 'POST',
                body,
            });

            if (!response.ok) {
                throw new Error(
                    `Import upload endpoint responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as UploadResult;

            uploadResult.value = payload;
            formatOverride.value = {};
            sheet.value = payload.sheets?.[0] ?? null;
            mapping.value = {
                columns: autoMatch(payload.headerSuggestion ?? [], fields),
                formats: {},
            };
            dryRunResult.value = null;
            error.value = null;
            currentStep.value = nextStepAfterUpload(payload);
        } catch {
            error.value = UPLOAD_ERROR_MESSAGE;
        } finally {
            loading.value = false;
        }
    };

    const selectSheet = (name: string): void => {
        sheet.value = name;
    };

    const setFormatOverride = (patch: ImportFormatOverride): void => {
        formatOverride.value = { ...formatOverride.value, ...patch };
    };

    const setMapping = (patch: Partial<MappingState>): void => {
        mapping.value = { ...mapping.value, ...patch };
    };

    const applyAutoMatch = (): void => {
        setMapping({
            columns: autoMatch(
                uploadResult.value?.headerSuggestion ?? [],
                fields,
            ),
        });
    };

    const listPresets = async (): Promise<void> => {
        try {
            const response = await request(
                presetIndexAction.url({ objectType }),
                {
                    method: 'GET',
                },
            );

            if (!response.ok) {
                throw new Error(
                    `Import preset index responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as {
                data?: ImportMappingPreset[];
            };

            presets.value = payload.data ?? [];
            error.value = null;
        } catch {
            error.value = PRESET_ERROR_MESSAGE;
        }
    };

    const savePreset = async (name: string): Promise<void> => {
        try {
            const response = await request(
                presetStoreAction.url({ objectType }),
                {
                    method: 'POST',
                    body: JSON.stringify({ name, mapping: mapping.value }),
                },
            );

            if (!response.ok) {
                throw new Error(
                    `Import preset store responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as {
                data?: ImportMappingPreset;
            };

            if (payload.data !== undefined && payload.data !== null) {
                presets.value = [...presets.value, payload.data];
            }

            error.value = null;
        } catch {
            error.value = PRESET_ERROR_MESSAGE;
        }
    };

    const loadPreset = (preset: ImportMappingPreset): void => {
        setMapping({
            columns: { ...preset.mapping.columns },
            formats: { ...preset.mapping.formats },
        });
    };

    const runDryRun = async (): Promise<void> => {
        if (uploadResult.value === null) {
            error.value = PREVIEW_ERROR_MESSAGE;

            return;
        }

        loading.value = true;

        try {
            const response = await request(previewAction.url({ objectType }), {
                method: 'POST',
                body: JSON.stringify({
                    path: uploadResult.value.path,
                    format: uploadResult.value.format,
                    sheet: sheet.value,
                    encoding:
                        formatOverride.value.encoding ??
                        uploadResult.value.encoding,
                    delimiter:
                        formatOverride.value.delimiter ??
                        uploadResult.value.delimiter,
                    mapping: {
                        columns: mapping.value.columns,
                        formats: mapping.value.formats,
                    },
                    duplicate_mode: duplicateMode.value,
                    missing_option_mode: missingOptionMode.value,
                }),
            });

            if (!response.ok) {
                throw new Error(await previewFailureMessage(response));
            }

            dryRunResult.value = (await response.json()) as DryRunResult;
            error.value = null;
        } catch (reason) {
            error.value =
                reason instanceof Error && reason.message !== ''
                    ? reason.message
                    : PREVIEW_ERROR_MESSAGE;
            dryRunResult.value = null;
        } finally {
            loading.value = false;
        }
    };

    const reset = (): void => {
        currentStep.value = 'upload';
        file.value = null;
        uploadResult.value = null;
        formatOverride.value = {};
        sheet.value = null;
        mapping.value = { columns: {}, formats: {} };
        duplicateMode.value = 'skip';
        missingOptionMode.value = 'error';
        dryRunResult.value = null;
        error.value = null;
    };

    watch(
        [
            () => mapping.value.columns,
            () => mapping.value.formats,
            duplicateMode,
            sheet,
            () => formatOverride.value,
        ],
        () => {
            dryRunResult.value = null;
        },
        { deep: true },
    );

    if (getCurrentScope()) {
        onScopeDispose(() => {
            for (const controller of controllers) {
                controller.abort();
            }

            controllers.clear();
        });
    }

    return {
        currentStep,
        file,
        uploadResult,
        formatOverride,
        sheet,
        mapping,
        duplicateMode,
        missingOptionMode,
        dryRunResult,
        loading,
        error,
        presets,
        canStartImport,
        upload,
        setFormatOverride,
        selectSheet,
        autoMatch: applyAutoMatch,
        setMapping,
        listPresets,
        savePreset,
        loadPreset,
        runDryRun,
        reset,
    };
}
