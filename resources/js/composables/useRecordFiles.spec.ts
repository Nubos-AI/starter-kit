import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, reactive, ref } from 'vue';
import { useRecordFiles } from '@/composables/useRecordFiles';
import type { RecordFile, RecordFilesResponse } from '@/types/attachments';
import { setUrlDefaults } from '@/wayfinder';

interface MockRequest {
    file?: File | null;
    processing: boolean;
    progress: { percentage: number } | null;
    errors: Record<string, string>;
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
    cancel: ReturnType<typeof vi.fn>;
}

const mocks = vi.hoisted(() => ({ requests: [] as MockRequest[] }));
vi.mock('@inertiajs/vue3', () => ({
    useHttp: (data: object) => {
        const request = reactive({
            ...data,
            processing: false,
            progress: null,
            errors: {},
            get: vi.fn(),
            post: vi.fn(),
            delete: vi.fn(),
            cancel: vi.fn(),
        });
        mocks.requests.push(request);

        return request;
    },
}));
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
    upload: { maxSizeKb: 10, allowedMimes: ['text/plain'] },
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
    mocks.requests.length = 0;
    setUrlDefaults({ activeTeam: 'test-team' });
});
afterEach(() => {
    scopes.splice(0).forEach((scope) => scope.stop());
});

describe('useRecordFiles', () => {
    it('loads permissions and paginates without replacing existing files', async () => {
        const { state } = setup();
        mocks.requests[0].get.mockResolvedValue(response);
        await flushPromises();
        await state.load();
        expect(state.files.value).toEqual([file]);
        expect(state.canUpload.value).toBe(true);
        expect(state.accept.value).toBe('text/plain');
        mocks.requests[0].get.mockResolvedValue({
            ...response,
            data: [{ ...file, id: 'file-2' }],
            meta: { current_page: 2, last_page: 2 },
        });
        await state.load(true);
        expect(state.files.value.map((item) => item.id)).toEqual([
            'file-1',
            'file-2',
        ]);
        expect(mocks.requests[0].get).toHaveBeenLastCalledWith(
            expect.stringContaining('page=2'),
        );
        expect(state.hasMore.value).toBe(false);
    });

    it('uploads a file through the multipart request and refreshes the list', async () => {
        const { state } = setup();
        mocks.requests[0].get.mockResolvedValue(response);
        await flushPromises();
        await state.load();
        const upload = new File(['hello'], 'brief.txt', { type: 'text/plain' });
        mocks.requests[1].post.mockImplementation(async () => {
            expect(mocks.requests[1].file).toBe(upload);

            return { data: file };
        });
        expect(await state.upload(upload)).toBe(true);
        expect(mocks.requests[1].post).toHaveBeenCalledWith(
            '/test-team/records/record-1/files',
        );
        expect(mocks.requests[1].file).toBeNull();
    });

    it('rejects oversized files without sending them', async () => {
        const { state } = setup();
        await flushPromises();
        state.canUpload.value = true;
        state.maxSizeKb.value = 1;
        expect(
            await state.upload(new File(['x'.repeat(2048)], 'big.txt')),
        ).toBe(false);
        expect(mocks.requests[1].post).not.toHaveBeenCalled();
        expect(state.error.value).toContain('zu groß');
    });

    it('preserves server validation messages', async () => {
        const { state } = setup();
        await flushPromises();
        state.canUpload.value = true;
        state.maxSizeKb.value = 10;
        mocks.requests[1].errors = {
            file: 'Dieser Dateityp ist nicht zugelassen.',
        };
        mocks.requests[1].post.mockRejectedValue(new Error('422'));
        expect(await state.upload(new File(['x'], 'script.php'))).toBe(false);
        expect(state.error.value).toContain('Dateityp');
    });

    it('does not remove a file after a failed delete', async () => {
        const { state } = setup();
        await flushPromises();
        state.files.value = [file];
        mocks.requests[2].delete.mockRejectedValue(new Error('503'));
        expect(await state.remove(file)).toBe(false);
        expect(state.files.value).toEqual([file]);
        expect(state.error.value).toContain('nicht gelöscht');
    });

    it('ignores a stale response after the record changes', async () => {
        const { state, id } = setup();
        await flushPromises();
        let resolve!: (value: RecordFilesResponse) => void;
        mocks.requests[0].get.mockReturnValueOnce(
            new Promise<RecordFilesResponse>((done) => {
                resolve = done;
            }),
        );
        const oldRequest = state.load();
        mocks.requests[0].get.mockResolvedValue({
            ...response,
            data: [],
            permissions: { canUpload: false },
        });
        id.value = 'record-2';
        await nextTick();
        await flushPromises();
        resolve(response);
        await oldRequest;
        expect(state.files.value).toEqual([]);
        expect(state.canUpload.value).toBe(false);
        expect(mocks.requests[0].cancel).toHaveBeenCalled();
    });

    it('cancels all requests when the panel unmounts', async () => {
        const { scope } = setup();
        await flushPromises();
        mocks.requests.forEach((request) => request.cancel.mockClear());
        scope.stop();
        mocks.requests.forEach((request) =>
            expect(request.cancel).toHaveBeenCalledOnce(),
        );
    });
});
