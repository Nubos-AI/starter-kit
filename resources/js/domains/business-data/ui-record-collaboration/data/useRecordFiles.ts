import { useHttp } from '@inertiajs/vue3';
import { computed, onScopeDispose, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { destroy, index, store } from '@/actions/App/Http/Controllers/Engine/RecordFilesController';
import type { RecordFile, RecordFilesResponse } from '@/domains/business-data/data';
import { useI18n } from '@/shared/composables/useI18n';

interface RecordFilesState {
  files: Ref<RecordFile[]>;
  error: Ref<string | null>;
  loading: ComputedRef<boolean>;
  uploading: ComputedRef<boolean>;
  deleting: ComputedRef<boolean>;
  progress: ComputedRef<number | undefined>;
  canUpload: Ref<boolean>;
  maxSizeKb: Ref<number>;
  hasMore: Ref<boolean>;
  load: (more?: boolean) => Promise<void>;
  upload: (file: File) => Promise<boolean>;
  remove: (file: RecordFile) => Promise<boolean>;
}

export function useRecordFiles(recordId: () => string): RecordFilesState {
  const { t } = useI18n();
  const listRequest = useHttp<Record<string, never>, RecordFilesResponse>({});
  const uploadRequest = useHttp<{ file: File | null }, { data: RecordFile }>({
    file: null,
  });
  const deleteRequest = useHttp({});
  const files = ref<RecordFile[]>([]);
  const error = ref<string | null>(null);
  const canUpload = ref(false);
  const maxSizeKb = ref(0);
  const hasMore = ref(false);
  let page = 0;
  let generation = 0;

  async function load(more = false): Promise<void> {
    const current = generation;
    error.value = null;

    try {
      const response = await listRequest.get(
        index.url({ record: recordId() }, { query: { page: more ? page + 1 : 1 } }),
      );

      if (current !== generation) {
        return;
      }

      files.value = more ? [...files.value, ...response.data] : response.data;
      page = response.meta.current_page;
      hasMore.value = page < response.meta.last_page;
      canUpload.value = response.permissions.canUpload;
      maxSizeKb.value = response.upload.maxSizeKb;
    } catch {
      if (current === generation) {
        error.value = t('i18n.composables.use_record_files.load_error');
      }
    }
  }

  function uploadErrorMessage(): string {
    return String(uploadRequest.errors.file ?? t('i18n.composables.use_record_files.upload_error'));
  }

  async function upload(file: File): Promise<boolean> {
    if (uploadRequest.processing || !canUpload.value) {
      return false;
    }

    if (file.size > maxSizeKb.value * 1024) {
      error.value = t('i18n.composables.use_record_files.file_too_large', {
        size: Math.round((maxSizeKb.value / 1024) * 10) / 10,
      });

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

      if (uploadRequest.hasErrors) {
        error.value = uploadErrorMessage();

        return false;
      }

      await load();

      return current === generation;
    } catch {
      if (current === generation) {
        error.value = uploadErrorMessage();
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
      await deleteRequest.delete(destroy.url({ record: recordId(), attachment: file.id }));

      if (current !== generation) {
        return false;
      }

      files.value = files.value.filter((entry) => entry.id !== file.id);
      await load();

      return current === generation;
    } catch {
      if (current === generation) {
        error.value = t('i18n.composables.use_record_files.delete_error');
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
