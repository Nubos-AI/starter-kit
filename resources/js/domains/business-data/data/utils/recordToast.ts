import { toast } from 'vue-sonner';
import type { TranslationParameters } from '@/shared/lib/i18n';

export type RecordToastAction = 'create' | 'edit' | 'delete';

type Translate = (key: string, parameters?: TranslationParameters) => string;

const TITLE_KEYS: Record<RecordToastAction, { one: string; many: string }> = {
  create: {
    one: 'i18n.lib.record_toast.created_one',
    many: 'i18n.lib.record_toast.created_many',
  },
  edit: {
    one: 'i18n.lib.record_toast.updated_one',
    many: 'i18n.lib.record_toast.updated_many',
  },
  delete: {
    one: 'i18n.lib.record_toast.deleted_one',
    many: 'i18n.lib.record_toast.deleted_many',
  },
};

export interface RecordToastOptions {
  count?: number;
  description?: string;
}

export function recordToast(
  action: RecordToastAction,
  t: Translate,
  options: RecordToastOptions = {},
): string | number {
  const description = options.description ?? '';
  const count = options.count ?? 1;
  const keys = TITLE_KEYS[action];

  return toast.success(count === 1 ? t(keys.one) : t(keys.many, { count }), {
    description: description === '' ? undefined : description,
  });
}
