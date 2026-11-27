import type { Ref } from 'vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { store, update } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { extractRecord } from '@/domains/business-data/data';
import { recordToast } from '@/domains/business-data/data';
import type { RecordObjectType, RecordPayload, RecordSaveScope } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import { buildHeaders } from '@/shared/composables/useRequestHeaders';
import { serialize } from '@/shared/composables/useUnsavedChanges';
import { readErrorResponse, toFieldErrors } from '@/shared/lib/errorResponse';

const REQUEST_TIMEOUT_MS = 15000;

const SAVE_ERROR_MESSAGE = 'i18n.composables.use_record_form.save_error';

const ASSIGNMENT_ERROR_MESSAGE = 'i18n.composables.use_record_form.assignment_error';

export type RecordFormMode = 'create' | 'edit';

export interface UseRecordFormOptions {
  mode: RecordFormMode;
  objectType: RecordObjectType;
  record: RecordPayload | null;
  collaboratorIds?: string[];
  attributes?: () => Record<string, unknown>;
  updates?: () => Record<string, unknown>;
  validateExtensions?: () => Record<string, string>;
  onSaved: (record: RecordPayload) => void;
}

export interface UseRecordFormReturn {
  values: Ref<Record<string, unknown>>;
  currentRecord: Ref<RecordPayload | null>;
  ownerId: Ref<string | null>;
  collaboratorIds: Ref<string[]>;
  errors: Ref<Record<string, string>>;
  saving: Ref<boolean>;
  conflictOpen: Ref<boolean>;
  submit: (scope?: RecordSaveScope) => Promise<boolean>;
  hasChanges: (fields: string[]) => boolean;
  changedValues: () => Record<string, unknown>;
  revert: (fields: string[]) => void;
  saveAssignment: () => Promise<void>;
  closeConflict: () => void;
  adoptRecord: (record: RecordPayload) => void;
}

export function useRecordForm(options: UseRecordFormOptions): UseRecordFormReturn {
  const { t } = useI18n();
  const values = ref<Record<string, unknown>>({
    ...(options.record?.data ?? {}),
  });
  const storedValues = ref<Record<string, unknown>>({ ...values.value });
  const ownerId = ref<string | null>(options.record?.ownerId ?? null);
  const collaboratorIds = ref<string[]>([...(options.collaboratorIds ?? [])]);
  const errors = ref<Record<string, string>>({});
  const saving = ref<boolean>(false);
  const conflictOpen = ref<boolean>(false);
  const current = ref<RecordPayload | null>(options.record);
  const confirmedOwnerId = ref<string | null>(options.record?.ownerId ?? null);
  const confirmedCollaboratorIds = ref<string[]>([...(options.collaboratorIds ?? [])]);

  const closeConflict = (): void => {
    conflictOpen.value = false;
  };

  const restoreAssignment = (): void => {
    ownerId.value = confirmedOwnerId.value;
    collaboratorIds.value = [...confirmedCollaboratorIds.value];
  };

  const rememberAssignment = (): void => {
    confirmedOwnerId.value = ownerId.value;
    confirmedCollaboratorIds.value = [...collaboratorIds.value];
  };

  const storedSnapshots = computed<Map<string, string>>(
    () => new Map(Object.entries(storedValues.value).map(([key, value]) => [key, serialize(value ?? null)])),
  );

  const isChanged = (key: string): boolean =>
    serialize(values.value[key] ?? null) !== (storedSnapshots.value.get(key) ?? serialize(null));

  const changedKeys = computed<Set<string>>(() => new Set(Object.keys(values.value).filter(isChanged)));

  const hasChanges = (fields: string[]): boolean => fields.some((key) => changedKeys.value.has(key));

  const revert = (fields: string[]): void => {
    values.value = {
      ...values.value,
      ...Object.fromEntries(fields.map((key) => [key, storedValues.value[key]])),
    };
  };

  const pick = (source: Record<string, unknown>, keys: string[]): Record<string, unknown> =>
    Object.fromEntries(keys.filter((key) => key in source).map((key) => [key, source[key]]));

  const editBody = (scope?: RecordSaveScope): Record<string, unknown> => {
    if (scope === undefined) {
      return {
        data: values.value,
        version: current.value?.version,
        owner_id: ownerId.value,
        collaborator_ids: collaboratorIds.value,
        ...options.updates?.(),
      };
    }

    return {
      ...(scope.fields.length > 0 ? { data: pick(values.value, scope.fields) } : {}),
      version: current.value?.version,
      ...pick(options.updates?.() ?? {}, scope.updates),
    };
  };

  const changedValues = (): Record<string, unknown> => pick(values.value, [...changedKeys.value]);

  const adoptStoredData = (data: Record<string, unknown>, scope?: RecordSaveScope): void => {
    const adopted = Object.keys(data).filter(
      (key) => scope === undefined || scope.fields.includes(key) || !isChanged(key),
    );
    const stored = pick(data, adopted);

    storedValues.value = { ...storedValues.value, ...stored };
    values.value = { ...values.value, ...stored };
  };

  const request = async (scope?: RecordSaveScope): Promise<Response> => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
      if (options.mode === 'edit' && current.value !== null) {
        return await fetch(update.url({ record: current.value.id }), {
          method: 'PUT',
          credentials: 'same-origin',
          headers: buildHeaders({ hasBody: true }),
          body: JSON.stringify(editBody(scope)),
          signal: controller.signal,
        });
      }

      return await fetch(store.url({ objectType: options.objectType }), {
        method: 'POST',
        credentials: 'same-origin',
        headers: buildHeaders({ hasBody: true }),
        body: JSON.stringify({
          data: values.value,
          owner_id: ownerId.value,
          collaborator_ids: collaboratorIds.value,
          ...options.attributes?.(),
        }),
        signal: controller.signal,
      });
    } finally {
      clearTimeout(timeout);
    }
  };

  const saveAssignment = async (): Promise<void> => {
    if (options.mode !== 'edit' || current.value === null || saving.value) {
      return;
    }

    saving.value = true;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
      const response = await fetch(update.url({ record: current.value.id }), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: buildHeaders({ hasBody: true }),
        body: JSON.stringify({
          version: current.value.version,
          owner_id: ownerId.value,
          collaborator_ids: collaboratorIds.value,
        }),
        signal: controller.signal,
      });

      if (response.status === 200) {
        current.value = extractRecord(await response.json());
        rememberAssignment();
        recordToast('edit', t);

        return;
      }

      if (response.status === 409) {
        current.value = extractRecord(await response.json());
        restoreAssignment();
        conflictOpen.value = true;

        return;
      }

      restoreAssignment();
      toast.error(t(ASSIGNMENT_ERROR_MESSAGE));
    } catch {
      restoreAssignment();
      toast.error(t(ASSIGNMENT_ERROR_MESSAGE));
    } finally {
      clearTimeout(timeout);
      saving.value = false;
    }
  };

  const submit = async (scope?: RecordSaveScope): Promise<boolean> => {
    if (saving.value) {
      return false;
    }

    const editScope = options.mode === 'edit' ? scope : undefined;

    errors.value = editScope === undefined ? (options.validateExtensions?.() ?? {}) : {};

    if (Object.keys(errors.value).length > 0) {
      return false;
    }

    saving.value = true;

    try {
      const response = await request(editScope);

      if (response.status === 200 || response.status === 201) {
        const record = extractRecord(await response.json());
        current.value = record;
        adoptStoredData(record.data, editScope);

        if (editScope === undefined) {
          rememberAssignment();
        }

        recordToast(options.mode === 'edit' ? 'edit' : 'create', t);
        options.onSaved(record);

        return true;
      }

      if (response.status === 409) {
        const fresh = extractRecord(await response.json());
        current.value = fresh;
        values.value = { ...fresh.data };
        storedValues.value = { ...fresh.data };
        conflictOpen.value = true;

        return false;
      }

      if (response.status === 422) {
        const failure = await readErrorResponse(response);
        errors.value = toFieldErrors(failure.errors);
        toast.error(failure.message ?? t(SAVE_ERROR_MESSAGE));

        return false;
      }

      toast.error(t(SAVE_ERROR_MESSAGE));
    } catch {
      toast.error(t(SAVE_ERROR_MESSAGE));
    } finally {
      saving.value = false;
    }

    return false;
  };

  const adoptRecord = (record: RecordPayload): void => {
    current.value = record;
  };

  return {
    values,
    currentRecord: current,
    ownerId,
    collaboratorIds,
    errors,
    saving,
    conflictOpen,
    submit,
    hasChanges,
    changedValues,
    revert,
    saveAssignment,
    closeConflict,
    adoptRecord,
  };
}
