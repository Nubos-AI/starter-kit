import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import type { Ref } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';

export interface SearchRecord {
    id: string;
    title: string;
}

export interface SearchGroup {
    type: string;
    label: string;
    records: SearchRecord[];
}

interface RawRecord {
    id: string;
    title: string;
}

interface RawGroup {
    type: string;
    label: string;
    records: RawRecord[];
}

interface SearchResponse {
    data: {
        groups: RawGroup[];
    };
}

export interface UseGlobalSearchReturn {
    query: Ref<string>;
    groups: Ref<SearchGroup[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    search: (term: string) => void;
    reset: () => void;
}

const DEBOUNCE_MS = 220;

const REQUEST_TIMEOUT_MS = 15000;

const SEARCH_ERROR_MESSAGE =
    'The search could not be carried out. Please try again.';

function readXsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

function buildHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = readXsrfToken();

    if (token !== null) {
        headers['X-XSRF-TOKEN'] = token;
    }

    return headers;
}

function normalizeType(raw: string): string {
    const separator = raw.lastIndexOf('\\');

    if (separator === -1) {
        return raw;
    }

    return raw.slice(separator + 1).toLowerCase();
}

function normalizeGroup(raw: RawGroup): SearchGroup {
    return {
        type: normalizeType(raw.type),
        label: raw.label,
        records: Array.isArray(raw.records)
            ? raw.records.map((record) => ({
                  id: record.id,
                  title: record.title,
              }))
            : [],
    };
}

export function useGlobalSearch(): UseGlobalSearchReturn {
    const query = ref<string>('');
    const groups = ref<SearchGroup[]>([]);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    let latestSeq = 0;
    let controller: AbortController | null = null;

    const runSearch = async (term: string): Promise<void> => {
        if (controller !== null) {
            controller.abort();
        }

        const localController = new AbortController();
        controller = localController;

        const seq = ++latestSeq;
        const timeout = setTimeout(
            () => localController.abort(),
            REQUEST_TIMEOUT_MS,
        );

        try {
            const response = await fetch(
                SearchController.url(undefined, { query: { q: term } }),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: buildHeaders(),
                    signal: localController.signal,
                },
            );

            if (seq !== latestSeq) {
                return;
            }

            if (!response.ok) {
                throw new Error(
                    `Search endpoint responded with status ${response.status}`,
                );
            }

            const body = (await response.json()) as SearchResponse;

            if (seq !== latestSeq) {
                return;
            }

            groups.value = body.data.groups.map(normalizeGroup);
            error.value = null;
        } catch {
            if (seq !== latestSeq) {
                return;
            }

            if (localController.signal.aborted) {
                return;
            }

            groups.value = [];
            error.value = SEARCH_ERROR_MESSAGE;
        } finally {
            if (seq === latestSeq) {
                loading.value = false;
            }

            clearTimeout(timeout);
        }
    };

    const debouncedRun = useDebounceFn(
        (term: string) => runSearch(term),
        DEBOUNCE_MS,
    );

    const reset = (): void => {
        if (controller !== null) {
            controller.abort();
            controller = null;
        }

        latestSeq += 1;
        groups.value = [];
        loading.value = false;
        error.value = null;
    };

    const search = (term: string): void => {
        const trimmed = term.trim();

        if (trimmed === '') {
            reset();

            return;
        }

        loading.value = true;
        error.value = null;
        void debouncedRun(trimmed);
    };

    watch(query, (term) => {
        search(term);
    });

    return {
        query,
        groups,
        loading,
        error,
        search,
        reset,
    };
}
