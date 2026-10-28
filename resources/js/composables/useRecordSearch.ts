import { useDebounceFn } from '@vueuse/core';
import type { Ref } from 'vue';
import { ref } from 'vue';

export const SEARCH_DEBOUNCE_MS = 150;

export const SEARCH_MINIMUM_LENGTH = 3;

export interface UseRecordSearchReturn {
    draft: Ref<string>;
    term: Ref<string | null>;
    setDraft: (value: string, immediate?: boolean) => void;
    clear: () => void;
}

export function useRecordSearch(): UseRecordSearchReturn {
    const draft = ref<string>('');
    const term = ref<string | null>(null);

    const updateTerm = (): void => {
        const trimmed = draft.value.trim();

        term.value = trimmed.length >= SEARCH_MINIMUM_LENGTH ? trimmed : null;
    };
    const commit = useDebounceFn(updateTerm, SEARCH_DEBOUNCE_MS);

    const setDraft = (value: string, immediate = false): void => {
        draft.value = value;

        if (immediate || value.trim() === '') {
            void commit.cancel?.();
            updateTerm();

            return;
        }

        void commit();
    };

    const clear = (): void => {
        setDraft('');
    };

    return { draft, term, setDraft, clear };
}
