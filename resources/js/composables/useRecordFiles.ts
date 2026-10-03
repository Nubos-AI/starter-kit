import { useHttp } from '@inertiajs/vue3';
import { computed, onScopeDispose, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import {
    destroy,
    index,
    store,
} from '@/actions/App/Http/Controllers/Engine/RecordFilesController';
import type { RecordFile, RecordFilesResponse } from '@/types/attachments';

interface RecordFilesState {
    files: Ref<RecordFile[]>;
    error: Ref<string | null>;
    loading: ComputedRef<boolean>;
    uploading: ComputedRef<boolean>;
    deleting: ComputedRef<boolean>;
    progress: ComputedRef<number | undefined>;
    canUpload: Ref<boolean>;
    accept: Ref<string>;
    maxSizeKb: Ref<number>;
    hasMore: Ref<boolean>;
    load: (more?: boolean) => Promise<void>;
    upload: (file: File) => Promise<boolean>;
    remove: (file: RecordFile) => Promise<boolean>;
}

export function useRecordFiles(recordId: () => string): RecordFilesState {
    const listRequest = useHttp<Record<string, never>, RecordFilesResponse>({});
    const uploadRequest = useHttp<{ file: File | null }, { data: RecordFile }>({
        file: null,
    });
    const deleteRequest = useHttp({});
    const files = ref<RecordFile[]>([]);
    const error = ref<string | null>(null);
    const canUpload = ref(false);
    const accept = ref('');
    const maxSizeKb = ref(0);
    const hasMore = ref(false);
    let page = 0;
    let generation = 0;

    async function load(more = false): Promise<void> {
        const current = generation;
        error.value = null;

        try {
            const response = await listRequest.get(
                index.url(
                    { record: recordId() },
                    { query: { page: more ? page + 1 : 1 } },
                ),
            );

            if (current !== generation) {
                return;
            }

            files.value = more
                ? [...files.value, ...response.data]
                : response.data;
            page = response.meta.current_page;
            hasMore.value = page < response.meta.last_page;
            canUpload.value = response.permissions.canUpload;
            maxSizeKb.value = response.upload.maxSizeKb;
            accept.value = response.upload.allowedMimes.join(',');
        } catch {
            if (current === generation) {
                error.value =
                    'Die Dateien konnten nicht geladen werden. Bitte versuchen Sie es erneut.';
            }
        }
    }

    async function upload(file: File): Promise<boolean> {
        if (uploadRequest.processing || !canUpload.value) {
            return false;
        }

        if (file.size > maxSizeKb.value * 1024) {
            error.value = `Die Datei ist zu groß. Erlaubt sind maximal ${Math.round((maxSizeKb.value / 1024) * 10) / 10} MB.`;

            return false;
        }

        const current = generation;
        error.value = null;
        uploadRequest.file = file;

        try {
            await uploadRequest.post(store.url({ record: recordId() }));

            if (current !== generation) {
                return false;
            }

            await load();

            return current === generation;
        } catch {
            if (current === generation) {
                error.value = String(
                    uploadRequest.errors.file ??
                        'Die Datei konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.',
                );
            }

            return false;
        } finally {
            uploadRequest.file = null;
        }
    }

    async function remove(file: RecordFile): Promise<boolean> {
        if (deleteRequest.processing) {
            return false;
        }

        const current = generation;
        error.value = null;

        try {
            await deleteRequest.delete(
                destroy.url({ record: recordId(), attachment: file.id }),
            );

            if (current !== generation) {
                return false;
            }

            files.value = files.value.filter((entry) => entry.id !== file.id);
            await load();

            return current === generation;
        } catch {
            if (current === generation) {
                error.value =
                    'Die Datei konnte nicht gelöscht werden. Bitte versuchen Sie es erneut.';
            }

            return false;
        }
    }

    function cancel(): void {
        generation++;
        listRequest.cancel();
        uploadRequest.cancel();
        deleteRequest.cancel();
    }

    watch(
        recordId,
        () => {
            cancel();
            files.value = [];
            canUpload.value = false;
            hasMore.value = false;
            page = 0;
            void load();
        },
        { immediate: true },
    );
    onScopeDispose(cancel);

    return {
        files,
        error,
        canUpload,
        accept,
        maxSizeKb,
        hasMore,
        load,
        upload,
        remove,
        loading: computed(() => listRequest.processing),
        uploading: computed(() => uploadRequest.processing),
        deleting: computed(() => deleteRequest.processing),
        progress: computed(() => uploadRequest.progress?.percentage),
    };
}
