import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import {
  index as presetIndex,
  store as presetStore,
} from '@/actions/App/Http/Controllers/Import/ImportMappingPresetsController';
import { preview as previewAction } from '@/actions/App/Http/Controllers/Import/ImportPreviewsController';
import { store as uploadAction } from '@/actions/App/Http/Controllers/Import/ImportUploadsController';
import { t } from '@/shared/testing/i18n';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import type { ServedRoute } from '@/shared/testing/msw';
import type { FieldDefinition } from '@/shared/types/fields';
import { setUrlDefaults } from '@/wayfinder';
import { autoMatch, useImportWizard } from './useImportWizard';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const OBJECT_TYPE = 'contacts';

setUrlDefaults({ activeTeam: 'nubos' });

const uploadRoute = uploadAction({ objectType: OBJECT_TYPE });

const previewRoute = previewAction({ objectType: OBJECT_TYPE });

const presetIndexRoute = presetIndex({ objectType: OBJECT_TYPE });

const presetStoreRoute = presetStore({ objectType: OBJECT_TYPE });

const uploadErrorMessage = 'Die Datei konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.';

const previewErrorMessage = 'Die Vorschau konnte nicht ermittelt werden. Bitte versuchen Sie es erneut.';

const presetErrorMessage = 'Die Vorlage konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

function makeField(key: string, label: string, overrides: Record<string, unknown> = {}): FieldDefinition {
  return {
    key,
    field_type: 'text_short',
    label,
    is_required: false,
    ...overrides,
  } as FieldDefinition;
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

function serveUploadAndPreview(): {
  uploaded: ServedRoute;
  previewed: ServedRoute;
} {
  return {
    uploaded: serveJson(uploadRoute, UPLOAD_RESPONSE),
    previewed: serveJson(previewRoute, DRY_RUN_RESPONSE),
  };
}

function csvFile(): File {
  return new File(['a,b\n1,2'], 'contacts.csv', { type: 'text/csv' });
}

async function wizardAfterDryRun(): Promise<{
  wizard: ReturnType<typeof useImportWizard>;
  previewed: ServedRoute;
}> {
  const { previewed } = serveUploadAndPreview();

  const wizard = useImportWizard(OBJECT_TYPE);

  await wizard.upload(csvFile());
  await flushPromises();

  wizard.setMapping({ columns: { 'First Name': 'first_name' } });
  wizard.duplicateMode.value = 'upsert';
  await nextTick();

  await wizard.runDryRun();
  await flushPromises();

  return { wizard, previewed };
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
  vi.restoreAllMocks();
});

describe('autoMatch (pure header → field-key resolver)', () => {
  it('maps the identity columns the export itself writes', () => {
    const result = autoMatch(['Externe Referenz-ID', 'Datensatznummer', 'external_reference_id'], [], t);

    expect(result['Externe Referenz-ID']).toBe('external_reference_id');
    expect(result['Datensatznummer']).toBe('record_number');
    expect(result['external_reference_id']).toBe('external_reference_id');
  });

  it('suggests field keys for headers matching case- and space-insensitively', () => {
    const fields = [makeField('first_name', 'First Name'), makeField('email', 'Email')];

    const result = autoMatch(['First Name', 'EMAIL'], fields, t);

    expect(result['First Name']).toBe('first_name');
    expect(result['EMAIL']).toBe('email');
  });

  it('omits headers that match no field instead of guessing', () => {
    const fields = [makeField('first_name', 'First Name')];

    const result = autoMatch(['First Name', 'Totally Unknown Column'], fields, t);

    expect(result).toHaveProperty('First Name', 'first_name');
    expect(result).not.toHaveProperty('Totally Unknown Column');
    expect(Object.keys(result)).toEqual(['First Name']);
  });
});

describe('useImportWizard — upload', () => {
  it('uploads the file as multipart and lands format + sheets, advancing the step', async () => {
    const { uploaded } = serveUploadAndPreview();

    const wizard = useImportWizard(OBJECT_TYPE);
    const initialStep = wizard.currentStep.value;

    await wizard.upload(csvFile());
    await flushPromises();

    expect(uploaded.requests).toHaveLength(1);

    const request = uploaded.lastRequest();
    expect(request?.method).toBe('POST');
    expect(request?.url.pathname).toBe(`/nubos/engine/import/${OBJECT_TYPE}/upload`);
    expect(request?.headers.get('Content-Type')).toMatch(/^multipart\/form-data; boundary=/);
    expect(request?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(String(request?.body)).toContain('name="file"');
    expect(String(request?.body)).toContain('Content-Type: text/csv');

    expect(wizard.uploadResult.value?.format).toBe('xlsx');
    expect(wizard.uploadResult.value?.sheets).toEqual(['Kontakte', 'Firmen']);
    expect(initialStep).toBe('upload');
    expect(wizard.currentStep.value).toBe('sheet');
  });

  it('is loading while the upload is in flight', async () => {
    const uploaded = serveJson(uploadRoute, UPLOAD_RESPONSE, {
      held: true,
    });

    const wizard = useImportWizard(OBJECT_TYPE);

    const uploading = wizard.upload(csvFile());
    await vi.waitFor(() => expect(uploaded.requests).toHaveLength(1));

    expect(wizard.loading.value).toBe(true);

    uploaded.release();
    await uploading;

    expect(wizard.loading.value).toBe(false);
  });

  it('goes straight on to the mapping for a file without several sheets', async () => {
    serveJson(uploadRoute, {
      ...UPLOAD_RESPONSE,
      format: 'csv',
      sheets: [],
    });

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.upload(csvFile());
    await flushPromises();

    expect(wizard.currentStep.value).toBe('mapping');
  });

  it('shows the reason the server gave for refusing the file and stays on the upload', async () => {
    serveValidationError(uploadRoute, {
      file: ['Die Datei überschreitet die Größengrenze von 2000 Byte.'],
    });

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.upload(csvFile());
    await flushPromises();

    expect(wizard.error.value).toBe('Die Datei überschreitet die Größengrenze von 2000 Byte.');
    expect(wizard.uploadResult.value).toBeNull();
    expect(wizard.currentStep.value).toBe('upload');
  });

  it('says the upload failed when the network drops the file', async () => {
    serveNetworkError(uploadRoute);

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.upload(csvFile());

    expect(wizard.error.value).toBe(uploadErrorMessage);
    expect(wizard.currentStep.value).toBe('upload');
    expect(wizard.loading.value).toBe(false);
  });
});

describe('useImportWizard — dry-run request contract', () => {
  it('sends duplicate_mode at top level (never nested in mapping) and stores the counts', async () => {
    const { wizard, previewed } = await wizardAfterDryRun();

    expect(previewed.requests).toHaveLength(1);
    expect(previewed.lastRequest()?.method).toBe('POST');
    expect(previewed.lastRequest()?.url.pathname).toBe(`/nubos/engine/import/${OBJECT_TYPE}/preview`);
    expect(previewed.lastRequest()?.headers.get('Content-Type')).toBe('application/json');

    const body = previewed.lastRequest()?.body as Record<string, unknown>;

    expect(body).toEqual(
      expect.objectContaining({
        path: UPLOAD_RESPONSE.path,
        format: 'xlsx',
        sheet: 'Kontakte',
        duplicate_mode: 'upsert',
        missing_option_mode: 'error',
        mapping: expect.objectContaining({
          columns: { 'First Name': 'first_name' },
        }),
      }),
    );
    expect((body.mapping as Record<string, unknown>).duplicate_mode).toBeUndefined();

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
    serveUploadAndPreview();

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.upload(csvFile());
    await flushPromises();
    expect(wizard.canStartImport.value).toBe(false);

    wizard.setMapping({ columns: { 'First Name': 'first_name' } });
    await wizard.runDryRun();
    await flushPromises();

    expect(wizard.canStartImport.value).toBe(true);
  });

  it('stays closed while the dry-run is still running', async () => {
    serveJson(uploadRoute, UPLOAD_RESPONSE);
    const previewed = serveJson(previewRoute, DRY_RUN_RESPONSE, {
      held: true,
    });

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.upload(csvFile());
    await flushPromises();

    const running = wizard.runDryRun();
    await vi.waitFor(() => expect(previewed.requests).toHaveLength(1));

    expect(wizard.loading.value).toBe(true);
    expect(wizard.canStartImport.value).toBe(false);

    previewed.release();
    await running;

    expect(wizard.loading.value).toBe(false);
    expect(wizard.canStartImport.value).toBe(true);
  });

  it.each<[string, (wizard: ReturnType<typeof useImportWizard>) => void]>([
    ['the mapping columns change', (wizard) => wizard.setMapping({ columns: { EMAIL: 'email' } })],
    ['the mapping formats change', (wizard) => wizard.setMapping({ formats: { 'First Name': 'date' } })],
    [
      'the duplicate mode changes',
      (wizard) => {
        wizard.duplicateMode.value = 'skip';
      },
    ],
    ['the selected sheet changes', (wizard) => wizard.selectSheet('Firmen')],
    ['the format override changes', (wizard) => wizard.setFormatOverride({ delimiter: ';' })],
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
    ['a server failure with the generic German text', 500, { message: 'Server Error' }, previewErrorMessage],
    [
      'a refused mapping with the reason the server gave',
      422,
      { message: 'Die Spalte „E-Mail“ ist doppelt zugeordnet.' },
      'Die Spalte „E-Mail“ ist doppelt zugeordnet.',
    ],
  ])('answers %s and resolves without opening the gate', async (_case, status, body, expected) => {
    serveJson(uploadRoute, UPLOAD_RESPONSE);
    serveJson(previewRoute, body, { status });

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.upload(csvFile());
    await flushPromises();
    wizard.setMapping({ columns: { 'First Name': 'first_name' } });

    await expect(wizard.runDryRun()).resolves.toBeUndefined();
    await flushPromises();

    expect(wizard.error.value).toBe(expected);
    expect(wizard.dryRunResult.value).toBeNull();
    expect(wizard.canStartImport.value).toBe(false);
  });

  it('answers a dry-run the network dropped with the generic German text', async () => {
    serveJson(uploadRoute, UPLOAD_RESPONSE);
    serveNetworkError(previewRoute);

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.upload(csvFile());
    await flushPromises();

    await wizard.runDryRun();

    expect(wizard.error.value).toBe(previewErrorMessage);
    expect(wizard.dryRunResult.value).toBeNull();
    expect(wizard.loading.value).toBe(false);
  });
});

describe('useImportWizard — mapping templates', () => {
  it('lists the stored templates of the object type', async () => {
    const preset = {
      id: '01PRESET00K5N3Q8V9WYE6M2H7',
      name: 'Standard',
      mapping: { columns: { EMAIL: 'email' }, formats: {} },
    };
    const served = serveJson(presetIndexRoute, { data: [preset] });

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.listPresets();

    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/import/${OBJECT_TYPE}/presets`);
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(wizard.presets.value).toEqual([preset]);
    expect(wizard.error.value).toBeNull();
  });

  it('names the template failure when the list cannot be loaded', async () => {
    serveServerError(presetIndexRoute);

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.listPresets();

    expect(wizard.presets.value).toEqual([]);
    expect(wizard.error.value).toBe(presetErrorMessage);
  });

  it('stores the current mapping under the given name and appends the template', async () => {
    const created = {
      id: '01PRESET10K5N3Q8V9WYE6M2H7',
      name: 'Standard',
      mapping: { columns: { EMAIL: 'email' }, formats: {} },
    };
    const served = serveJson(presetStoreRoute, { data: created }, { status: 201 });

    const wizard = useImportWizard(OBJECT_TYPE);
    wizard.setMapping({ columns: { EMAIL: 'email' } });

    await wizard.savePreset('Standard');

    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(served.lastRequest()?.body).toEqual({
      name: 'Standard',
      mapping: { columns: { EMAIL: 'email' }, formats: {} },
    });
    expect(wizard.presets.value).toEqual([created]);
    expect(wizard.error.value).toBeNull();
  });
});

describe('useImportWizard — the error branches', () => {
  it('says the upload failed, keeps the step and ends loading on a refused upload', async () => {
    serveServerError(uploadRoute, {});

    const wizard = useImportWizard(OBJECT_TYPE);
    const initialStep = wizard.currentStep.value;

    await wizard.upload(csvFile());
    await flushPromises();

    expect(wizard.error.value).toBe(uploadErrorMessage);
    expect(wizard.uploadResult.value).toBeNull();
    expect(wizard.currentStep.value).toBe(initialStep);
    expect(wizard.loading.value).toBe(false);
  });

  it('says the template could not be saved and keeps the list when saving is refused', async () => {
    serveNetworkError(presetStoreRoute);

    const wizard = useImportWizard(OBJECT_TYPE);

    await wizard.savePreset('Standard');

    expect(wizard.error.value).toBe(presetErrorMessage);
    expect(wizard.presets.value).toEqual([]);
  });
});
