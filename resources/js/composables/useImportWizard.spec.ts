import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { preview as previewAction } from '@/actions/App/Http/Controllers/Import/ImportPreviewsController';
import { store as uploadAction } from '@/actions/App/Http/Controllers/Import/ImportUploadsController';
import { autoMatch, useImportWizard } from '@/composables/useImportWizard';
import type { FieldDefinition } from '@/types/fields';

const OBJECT_TYPE = 'contacts';

vi.mock(
    '@/actions/App/Http/Controllers/Import/ImportUploadsController',
    () => ({
        store: { url: () => '/api/import/upload', method: 'post' },
    }),
);

vi.mock(
    '@/actions/App/Http/Controllers/Import/ImportPreviewsController',
    () => ({
        preview: { url: () => '/api/import/preview', method: 'post' },
    }),
);

vi.mock(
    '@/actions/App/Http/Controllers/Import/ImportMappingPresetsController',
    () => ({
        index: { url: () => '/api/import/presets', method: 'get' },
        store: { url: () => '/api/import/presets', method: 'post' },
    }),
);

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function makeField(
    key: string,
    label: string,
    overrides: Record<string, unknown> = {},
): FieldDefinition {
    return {
        key,
        field_type: 'text_short',
        label,
        is_required: false,
        ...overrides,
    } as FieldDefinition;
}

function initOf(fetchMock: ReturnType<typeof vi.fn>, url: string): RequestInit {
    const call = fetchMock.mock.calls.find((entry) => entry[0] === url);
    expect(call).toBeTruthy();

    return call?.[1] as RequestInit;
}

function jsonBodyOf(
    fetchMock: ReturnType<typeof vi.fn>,
    url: string,
): Record<string, unknown> {
    const raw = initOf(fetchMock, url).body;
    expect(typeof raw).toBe('string');

    return JSON.parse(raw as string) as Record<string, unknown>;
}

const UPLOAD_RESPONSE = {
    path: 'imports/tmp/abc.xlsx',
    format: 'xlsx',
    sheets: ['Kontakte', 'Firmen'],
    headerSuggestion: ['First Name', 'EMAIL'],
};

const DRY_RUN_RESPONSE = {
    new: 5,
    updates: 2,
    errors: 1,
    sampleErrors: [{ row: 3, message: 'Invalid email' }],
};

function routedFetch(): ReturnType<typeof vi.fn> {
    return vi.fn((url: string) => {
        if (url === uploadAction.url({ objectType: OBJECT_TYPE })) {
            return Promise.resolve(jsonResponse(200, UPLOAD_RESPONSE));
        }

        if (url === previewAction.url({ objectType: OBJECT_TYPE })) {
            return Promise.resolve(jsonResponse(200, DRY_RUN_RESPONSE));
        }

        return Promise.resolve(jsonResponse(200, { data: [] }));
    });
}

function csvFile(): File {
    return new File(['a,b\n1,2'], 'contacts.csv', { type: 'text/csv' });
}

async function wizardAfterDryRun(): Promise<{
    wizard: ReturnType<typeof useImportWizard>;
    fetchMock: ReturnType<typeof vi.fn>;
}> {
    const fetchMock = routedFetch();
    vi.stubGlobal('fetch', fetchMock);

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.upload(csvFile());
    await flushPromises();

    wizard.setMapping({ columns: { 'First Name': 'first_name' } });
    wizard.duplicateMode.value = 'upsert';
    await nextTick();

    await wizard.runDryRun();
    await flushPromises();

    return { wizard, fetchMock };
}

beforeEach(() => {
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=test-xsrf-token',
    } as unknown as Document);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('autoMatch (pure header → field-key resolver)', () => {
    it('maps the identity columns the export itself writes', () => {
        const result = autoMatch(
            ['Externe Referenz-ID', 'Datensatznummer', 'external_reference_id'],
            [],
        );

        expect(result['Externe Referenz-ID']).toBe('external_reference_id');
        expect(result['Datensatznummer']).toBe('record_number');
        expect(result['external_reference_id']).toBe('external_reference_id');
    });

    it('suggests field keys for headers matching case- and space-insensitively', () => {
        const fields = [
            makeField('first_name', 'First Name'),
            makeField('email', 'Email'),
        ];

        const result = autoMatch(['First Name', 'EMAIL'], fields);

        expect(result['First Name']).toBe('first_name');
        expect(result['EMAIL']).toBe('email');
    });

    it('omits headers that match no field instead of guessing', () => {
        const fields = [makeField('first_name', 'First Name')];

        const result = autoMatch(
            ['First Name', 'Totally Unknown Column'],
            fields,
        );

        expect(result).toHaveProperty('First Name', 'first_name');
        expect(result).not.toHaveProperty('Totally Unknown Column');
        expect(Object.keys(result)).toEqual(['First Name']);
    });
});

describe('useImportWizard — upload', () => {
    it('uploads the file as multipart and lands format + sheets, advancing the step', async () => {
        const fetchMock = routedFetch();
        vi.stubGlobal('fetch', fetchMock);

        const wizard = useImportWizard(OBJECT_TYPE);
        const initialStep = wizard.currentStep.value;

        await wizard.upload(csvFile());
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            uploadAction.url({ objectType: OBJECT_TYPE }),
            expect.anything(),
        );

        const init = initOf(
            fetchMock,
            uploadAction.url({ objectType: OBJECT_TYPE }),
        );
        expect(String(init.method).toUpperCase()).toBe('POST');
        expect(init.body).toBeInstanceOf(FormData);
        expect((init.body as FormData).get('file')).not.toBeNull();

        expect(wizard.uploadResult.value?.format).toBe('xlsx');
        expect(wizard.uploadResult.value?.sheets).toEqual([
            'Kontakte',
            'Firmen',
        ]);
        expect(initialStep).toBe('upload');
        expect(wizard.currentStep.value).toBe('sheet');
    });

    it('goes straight on to the mapping for a file without several sheets', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(200, {
                        ...UPLOAD_RESPONSE,
                        format: 'csv',
                        sheets: [],
                    }),
                ),
            ),
        );

        const wizard = useImportWizard(OBJECT_TYPE);

        await wizard.upload(csvFile());
        await flushPromises();

        expect(wizard.currentStep.value).toBe('mapping');
    });
});

describe('useImportWizard — dry-run request contract', () => {
    it('sends duplicate_mode at top level (never nested in mapping) and stores the counts', async () => {
        const { wizard, fetchMock } = await wizardAfterDryRun();

        const body = jsonBodyOf(
            fetchMock,
            previewAction.url({ objectType: OBJECT_TYPE }),
        );

        expect(body).toEqual(
            expect.objectContaining({
                duplicate_mode: 'upsert',
                mapping: expect.objectContaining({
                    columns: { 'First Name': 'first_name' },
                }),
            }),
        );
        expect(
            (body.mapping as Record<string, unknown>).duplicate_mode,
        ).toBeUndefined();

        expect(wizard.dryRunResult.value).toEqual(
            expect.objectContaining({
                new: 5,
                updates: 2,
                errors: 1,
                sampleErrors: [{ row: 3, message: 'Invalid email' }],
            }),
        );
    });
});

describe('useImportWizard — canStartImport gate', () => {
    it('is false before any dry-run and true after a successful one', async () => {
        const fetchMock = routedFetch();
        vi.stubGlobal('fetch', fetchMock);

        const wizard = useImportWizard(OBJECT_TYPE);

        await wizard.upload(csvFile());
        await flushPromises();
        expect(wizard.canStartImport.value).toBe(false);

        wizard.setMapping({ columns: { 'First Name': 'first_name' } });
        await wizard.runDryRun();
        await flushPromises();

        expect(wizard.canStartImport.value).toBe(true);
    });

    it.each<[string, (wizard: ReturnType<typeof useImportWizard>) => void]>([
        [
            'the mapping columns change',
            (wizard) => wizard.setMapping({ columns: { EMAIL: 'email' } }),
        ],
        [
            'the mapping formats change',
            (wizard) =>
                wizard.setMapping({ formats: { 'First Name': 'date' } }),
        ],
        [
            'the duplicate mode changes',
            (wizard) => {
                wizard.duplicateMode.value = 'skip';
            },
        ],
        [
            'the selected sheet changes',
            (wizard) => wizard.selectSheet('Firmen'),
        ],
        [
            'the format override changes',
            (wizard) => wizard.setFormatOverride({ delimiter: ';' }),
        ],
    ])('re-disables when %s after a dry-run', async (_label, change) => {
        const { wizard } = await wizardAfterDryRun();
        expect(wizard.canStartImport.value).toBe(true);

        change(wizard);
        await nextTick();

        expect(wizard.dryRunResult.value).toBeNull();
        expect(wizard.canStartImport.value).toBe(false);
    });
});

describe('useImportWizard — failure handling', () => {
    it.each([
        [
            'a server failure with the generic German text',
            500,
            { message: 'Server Error' },
            'Die Vorschau konnte nicht ermittelt werden. Bitte versuchen Sie es erneut.',
        ],
        [
            'a refused mapping with the reason the server gave',
            422,
            { message: 'Die Spalte „E-Mail“ ist doppelt zugeordnet.' },
            'Die Spalte „E-Mail“ ist doppelt zugeordnet.',
        ],
    ])(
        'answers %s and resolves without opening the gate',
        async (_case, status, body, expected) => {
            const fetchMock = vi.fn((url: string) => {
                if (url === uploadAction.url({ objectType: OBJECT_TYPE })) {
                    return Promise.resolve(jsonResponse(200, UPLOAD_RESPONSE));
                }

                return Promise.resolve(jsonResponse(status, body));
            });
            vi.stubGlobal('fetch', fetchMock);

            const wizard = useImportWizard(OBJECT_TYPE);

            await wizard.upload(csvFile());
            await flushPromises();
            wizard.setMapping({ columns: { 'First Name': 'first_name' } });

            await expect(wizard.runDryRun()).resolves.toBeUndefined();
            await flushPromises();

            expect(wizard.error.value).toBe(expected);
            expect(wizard.dryRunResult.value).toBeNull();
            expect(wizard.canStartImport.value).toBe(false);
        },
    );
});

describe('useImportWizard — the error branches', () => {
    it('says the upload failed, keeps the step and ends loading on a refused upload', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(500, {}))),
        );

        const wizard = useImportWizard(OBJECT_TYPE);
        const initialStep = wizard.currentStep.value;

        await wizard.upload(csvFile());
        await flushPromises();

        expect(wizard.error.value).toBe(
            'Die Datei konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.',
        );
        expect(wizard.uploadResult.value).toBeNull();
        expect(wizard.currentStep.value).toBe(initialStep);
        expect(wizard.loading.value).toBe(false);
    });

    it('says the template could not be saved and keeps the list when saving is refused', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new Error('network down'))),
        );

        const wizard = useImportWizard(OBJECT_TYPE);

        await wizard.savePreset('Standard');

        expect(wizard.error.value).toBe(
            'Die Vorlage konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        );
        expect(wizard.presets.value).toEqual([]);
    });
});
