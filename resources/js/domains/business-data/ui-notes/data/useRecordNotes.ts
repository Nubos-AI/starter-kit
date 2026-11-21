import type { Ref } from 'vue';
import { getCurrentScope, onScopeDispose, ref } from 'vue';
import { store as storeAction } from '@/actions/App/Http/Controllers/Notes/RecordNotesController';
import { useI18n } from '@/shared/composables/useI18n';
import { buildHeaders } from '@/shared/composables/useRequestHeaders';
import { readBodyReason } from '@/shared/lib/errorResponse';
import { asNullableText, labelledRefOf } from '@/shared/lib/guards';
import type { LabelledRef } from '@/shared/types/ui';

const REQUEST_TIMEOUT_MS = 15000;

const EMPTY_BODY_MESSAGE = 'i18n.composables.use_record_notes.empty_body';

const SAVE_ERROR_MESSAGE = 'i18n.composables.use_record_notes.save_error';

export type RecordNoteAuthorRef = LabelledRef;

export interface RecordNoteItem {
  id: string;
  body: string;
  author: RecordNoteAuthorRef | null;
  createdAt: string | null;
  updatedAt: string | null;
}

export interface UseRecordNotesReturn {
  saving: Ref<boolean>;
  error: Ref<string | null>;
  create: (recordId: string, body: string) => Promise<RecordNoteItem | null>;
}

function normalizeItem(raw: unknown): RecordNoteItem {
  const source = (raw ?? {}) as Record<string, unknown>;

  return {
    id: String(source.id ?? ''),
    body: String(source.body ?? ''),
    author: labelledRefOf(source.author ?? null),
    createdAt: asNullableText(source.createdAt),
    updatedAt: asNullableText(source.updatedAt),
  };
}

export function useRecordNotes(): UseRecordNotesReturn {
  const { t } = useI18n();
  const saving = ref<boolean>(false);
  const error = ref<string | null>(null);

  const controllers = new Set<AbortController>();

  const request = async (url: string, init: RequestInit): Promise<Response> => {
    const controller = new AbortController();
    controllers.add(controller);
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
      return await fetch(url, {
        credentials: 'same-origin',
        headers: buildHeaders({ hasBody: true }),
        signal: controller.signal,
        ...init,
      });
    } finally {
      clearTimeout(timeout);
      controllers.delete(controller);
    }
  };

  const create = async (recordId: string, body: string): Promise<RecordNoteItem | null> => {
    const trimmed = body.trim();

    if (trimmed === '') {
      error.value = t(EMPTY_BODY_MESSAGE);

      return null;
    }

    saving.value = true;

    try {
      const response = await request(storeAction.url({ record: recordId }), {
        method: 'POST',
        body: JSON.stringify({ body: trimmed }),
      });

      const payload = (await response.json()) as { data?: unknown };

      if (!response.ok) {
        error.value = readBodyReason(payload) ?? t(SAVE_ERROR_MESSAGE);

        return null;
      }

      error.value = null;

      return payload.data === undefined || payload.data === null ? null : normalizeItem(payload.data);
    } catch {
      error.value = t(SAVE_ERROR_MESSAGE);

      return null;
    } finally {
      saving.value = false;
    }
  };

  if (getCurrentScope()) {
    onScopeDispose(() => {
      for (const controller of controllers) {
        controller.abort();
      }

      controllers.clear();
    });
  }

  return { saving, error, create };
}
