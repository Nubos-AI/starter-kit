import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { destroy, index, store } from '@/actions/App/Http/Controllers/Engine/RecordFilesController';
import {
  serveEmpty,
  serveJson,
  serveServerError,
  serveValidationError,
  trackInertiaRequestSignals,
} from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordFile, RecordFilesResponse } from '../../data/types/attachments';
import { useRecordFiles } from './useRecordFiles';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const file: RecordFile = {
  id: 'file-1',
  fileName: 'brief.txt',
  mimeType: 'text/plain',
  size: 20,
  fieldKey: null,
  createdAt: null,
  uploadedBy: null,
  canDelete: true,
};
const response: RecordFilesResponse = {
  data: [file],
  meta: { current_page: 1, last_page: 2, total: 26 },
  permissions: { canUpload: true },
  upload: { maxSizeKb: 10 },
};
const emptyResponse: RecordFilesResponse = {
  ...response,
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  permissions: { canUpload: false },
};
const scopes: ReturnType<typeof effectScope>[] = [];

function setup() {
  const id = ref('record-1');
  const scope = effectScope();
  scopes.push(scope);
  const state = scope.run(() => useRecordFiles(() => id.value))!;

  return { state, id, scope };
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'test-team' });
});
afterEach(() => {
  scopes.splice(0).forEach((scope) => scope.stop());
  vi.restoreAllMocks();
});

describe('useRecordFiles', () => {
  it('loads permissions and paginates without replacing existing files', async () => {
    const listed = serveJson(index({ record: 'record-1' }), response, {
      once: true,
    });
    const { state } = setup();
    await vi.waitFor(() => expect(state.files.value).toEqual([file]));

    expect(state.canUpload.value).toBe(true);
    expect(state.maxSizeKb.value).toBe(10);
    expect(state).not.toHaveProperty('accept');
    expect(listed.lastRequest()?.url.searchParams.get('page')).toBe('1');

    const nextPage = serveJson(index({ record: 'record-1' }), {
      ...response,
      data: [{ ...file, id: 'file-2' }],
      meta: { current_page: 2, last_page: 2, total: 26 },
    });
    await state.load(true);

    expect(state.files.value.map((item) => item.id)).toEqual(['file-1', 'file-2']);
    expect(nextPage.lastRequest()?.url.searchParams.get('page')).toBe('2');
    expect(state.hasMore.value).toBe(false);
  });

  it('reports a failed list request', async () => {
    serveServerError(index({ record: 'record-1' }));
    const { state } = setup();

    await vi.waitFor(() =>
      expect(state.error.value).toBe('Die Dateien konnten nicht geladen werden. Bitte versuchen Sie es erneut.'),
    );
    expect(state.files.value).toEqual([]);
    expect(state.canUpload.value).toBe(false);
  });

  it('marks the list as loading until the server answers', async () => {
    const listed = serveJson(index({ record: 'record-1' }), response, {
      held: true,
    });
    const { state } = setup();
    await vi.waitFor(() => expect(listed.requests).toHaveLength(1));

    expect(state.loading.value).toBe(true);
    expect(state.files.value).toEqual([]);

    listed.release();

    await vi.waitFor(() => expect(state.loading.value).toBe(false));
    expect(state.files.value).toEqual([file]);
  });

  it('uploads a file through the multipart request and refreshes the list', async () => {
    const listed = serveJson(index({ record: 'record-1' }), response);
    const uploaded = serveJson(store({ record: 'record-1' }), {
      data: file,
    });
    const { state } = setup();
    await vi.waitFor(() => expect(state.canUpload.value).toBe(true));
    const listCallsBeforeUpload = listed.requests.length;
    const upload = new File(['hello'], 'brief.txt', { type: 'text/plain' });

    expect(await state.upload(upload)).toBe(true);

    expect(uploaded.requests).toHaveLength(1);
    expect(uploaded.lastRequest()?.url.pathname).toBe('/test-team/records/record-1/files');
    const sent = String(uploaded.lastRequest()?.body);
    expect(sent).toContain('Content-Disposition: form-data; name="file"');
    expect(sent).toContain('Content-Type: text/plain');
    expect(listed.requests.length).toBe(listCallsBeforeUpload + 1);
    expect(state.uploading.value).toBe(false);
  });

  it('rejects oversized files without sending them', async () => {
    serveJson(index({ record: 'record-1' }), response);
    const uploaded = serveJson(store({ record: 'record-1' }), {
      data: file,
    });
    const { state } = setup();
    await vi.waitFor(() => expect(state.canUpload.value).toBe(true));
    state.maxSizeKb.value = 1;

    expect(await state.upload(new File(['x'.repeat(2048)], 'big.txt'))).toBe(false);
    expect(uploaded.requests).toHaveLength(0);
    expect(state.error.value).toContain('zu groß');
  });

  it('preserves server validation messages', async () => {
    const listed = serveJson(index({ record: 'record-1' }), response);
    serveValidationError(store({ record: 'record-1' }), {
      file: ['Dieser Dateityp ist nicht zugelassen.'],
    });
    const { state } = setup();
    await vi.waitFor(() => expect(state.canUpload.value).toBe(true));

    expect(await state.upload(new File(['x'], 'script.php'))).toBe(false);
    expect(state.error.value).toBe('Dieser Dateityp ist nicht zugelassen.');
    expect(listed.requests).toHaveLength(1);
  });

  it('falls back to the generic upload notice when the server fails', async () => {
    serveJson(index({ record: 'record-1' }), response);
    serveServerError(store({ record: 'record-1' }));
    const { state } = setup();
    await vi.waitFor(() => expect(state.canUpload.value).toBe(true));

    expect(await state.upload(new File(['x'], 'brief.txt'))).toBe(false);
    expect(state.error.value).toBe('Die Datei konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.');
  });

  it('removes a deleted file and refreshes the list', async () => {
    const listed = serveJson(index({ record: 'record-1' }), response, {
      once: true,
    });
    const deleted = serveEmpty(destroy({ record: 'record-1', attachment: file.id }));
    const { state } = setup();
    await vi.waitFor(() => expect(state.files.value).toEqual([file]));
    serveJson(index({ record: 'record-1' }), emptyResponse);

    expect(await state.remove(file)).toBe(true);

    expect(deleted.lastRequest()?.method).toBe('DELETE');
    expect(listed.requests).toHaveLength(1);
    expect(state.files.value).toEqual([]);
  });

  it('does not remove a file after a failed delete', async () => {
    serveJson(index({ record: 'record-1' }), response);
    serveServerError(
      destroy({ record: 'record-1', attachment: file.id }),
      { message: 'Service Unavailable' },
      { status: 503 },
    );
    const { state } = setup();
    await vi.waitFor(() => expect(state.files.value).toEqual([file]));

    expect(await state.remove(file)).toBe(false);
    expect(state.files.value).toEqual([file]);
    expect(state.error.value).toContain('nicht gelöscht');
  });

  it('ignores a stale response after the record changes', async () => {
    const signals = trackInertiaRequestSignals();
    const stale = serveJson(index({ record: 'record-1' }), response, {
      held: true,
    });
    serveJson(index({ record: 'record-2' }), emptyResponse);
    const { state, id } = setup();
    await vi.waitFor(() => expect(stale.requests).toHaveLength(1));

    id.value = 'record-2';
    await nextTick();
    await flushPromises();
    stale.release();
    await flushPromises();

    expect(signals[0].aborted).toBe(true);
    expect(signals[1].aborted).toBe(false);
    expect(state.files.value).toEqual([]);
    expect(state.canUpload.value).toBe(false);
  });

  it('cancels all requests when the panel unmounts', async () => {
    const signals = trackInertiaRequestSignals();
    const listed = serveJson(index({ record: 'record-1' }), response, {
      held: true,
    });
    const { state, scope } = setup();
    await vi.waitFor(() => expect(listed.requests).toHaveLength(1));

    scope.stop();
    listed.release();
    await flushPromises();

    expect(signals).toHaveLength(1);
    expect(signals[0].aborted).toBe(true);
    expect(state.files.value).toEqual([]);
  });
});
