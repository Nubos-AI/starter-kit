import type { Ref } from 'vue';
import { ref, watch } from 'vue';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { lookup } from '@/routes/engine/records';
import type { SelectOption } from '@/types/ui';

const DEBOUNCE_MS = 250;

interface LookupResponse {
    data: SelectOption[];
    meta?: { hasMore?: boolean };
}

export interface UseRecordLookupReturn {
    options: Ref<SelectOption[]>;
    loading: Ref<boolean>;
    search: Ref<string>;
    hasMore: Ref<boolean>;
    reload: () => Promise<void>;
    loadMore: () => Promise<void>;
}

export function useRecordLookup(
    objectTypeSlug: Ref<string | null>,
): UseRecordLookupReturn {
    const options = ref<SelectOption[]>([]);
    const loading = ref<boolean>(false);
    const search = ref<string>('');
    const hasMore = ref<boolean>(false);

    let timer: ReturnType<typeof setTimeout> | null = null;
    let requestId = 0;

    async function fetchPage(offset: number): Promise<void> {
        const slug = objectTypeSlug.value;

        if (slug === null || slug === '') {
            options.value = [];
            hasMore.value = false;

            return;
        }

        const current = ++requestId;
        loading.value = true;

        try {
            const response = await fetch(
                lookup.url(
                    { objectType: slug },
                    { query: { q: search.value, offset: String(offset) } },
                ),
                { headers: buildHeaders(), credentials: 'same-origin' },
            );

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const payload = (await response.json()) as LookupResponse;

            if (current === requestId) {
                options.value =
                    offset === 0
                        ? payload.data
                        : [...options.value, ...payload.data];
                hasMore.value = payload.meta?.hasMore ?? false;
            }
        } catch {
            if (current === requestId) {
                if (offset === 0) {
                    options.value = [];
                }

                hasMore.value = false;
            }
        } finally {
            if (current === requestId) {
                loading.value = false;
            }
        }
    }

    async function reload(): Promise<void> {
        await fetchPage(0);
    }

    async function loadMore(): Promise<void> {
        if (!hasMore.value || loading.value) {
            return;
        }

        await fetchPage(options.value.length);
    }

    function schedule(): void {
        if (timer !== null) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            void reload();
        }, DEBOUNCE_MS);
    }

    watch(search, schedule);
    watch(objectTypeSlug, () => void reload(), { immediate: true });

    return { options, loading, search, hasMore, reload, loadMore };
}
