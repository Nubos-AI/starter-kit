import { useTimeoutFn } from '@vueuse/core';
import { readonly, ref } from 'vue';
import type { Ref } from 'vue';
import { toast } from 'vue-sonner';
import { useI18n } from '@/shared/composables/useI18n';

export type ClipboardOutcome = 'copied' | 'failed';

export interface ClipboardMessages {
  copied?: string | false;
  failed?: string;
}

export interface UseClipboardReturn {
  copied: Readonly<Ref<boolean>>;
  copy: (text: string) => Promise<ClipboardOutcome>;
}

export function useClipboard(messages: ClipboardMessages = {}): UseClipboardReturn {
  const { t } = useI18n();
  const copied = ref<boolean>(false);
  const reset = useTimeoutFn(
    () => {
      copied.value = false;
    },
    1500,
    { immediate: false },
  );

  async function copy(text: string): Promise<ClipboardOutcome> {
    try {
      if (navigator.clipboard === undefined) {
        throw new Error('The browser offers no clipboard.');
      }

      await navigator.clipboard.writeText(text);
    } catch {
      copied.value = false;
      toast.error(messages.failed ?? t('i18n.composables.use_clipboard.copy_failed'));

      return 'failed';
    }

    copied.value = true;
    reset.start();

    if (messages.copied !== false) {
      toast.success(messages.copied ?? t('i18n.composables.use_clipboard.copied'));
    }

    return 'copied';
  }

  return { copied: readonly(copied), copy };
}
