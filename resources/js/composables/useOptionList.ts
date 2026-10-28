import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import type { SelectOption } from '@/types/ui';

const LOAD_MORE_THRESHOLD = 120;

export interface OptionListSource {
    options: () => SelectOption[];
    searchable: () => boolean;
    serverSearch: () => boolean;
    onSearch: (term: string) => void;
    onLoadMore: () => void;
}

export interface UseOptionListReturn {
    searchTerm: Ref<string>;
    visibleOptions: ComputedRef<SelectOption[]>;
    setSearchTerm: (term: string) => void;
    resetSearch: () => void;
    onViewportScroll: (event: Event) => void;
}

export function useOptionList(source: OptionListSource): UseOptionListReturn {
    const searchTerm = ref<string>('');

    const visibleOptions = computed<SelectOption[]>(() => {
        const term = searchTerm.value.toLocaleLowerCase();

        if (
            !source.searchable() ||
            source.serverSearch() ||
            term.trim() === ''
        ) {
            return source.options();
        }

        return source
            .options()
            .filter((option) =>
                `${option.label} ${option.description ?? ''}`
                    .toLocaleLowerCase()
                    .includes(term),
            );
    });

    function setSearchTerm(term: string): void {
        searchTerm.value = term;

        if (source.serverSearch()) {
            source.onSearch(term);
        }
    }

    function resetSearch(): void {
        const hadTerm = searchTerm.value !== '';

        searchTerm.value = '';

        if (hadTerm && source.serverSearch()) {
            source.onSearch('');
        }
    }

    function onViewportScroll(event: Event): void {
        const element = event.target;

        if (!source.serverSearch() || !(element instanceof HTMLElement)) {
            return;
        }

        const remaining =
            element.scrollHeight - element.scrollTop - element.clientHeight;

        if (remaining <= LOAD_MORE_THRESHOLD) {
            source.onLoadMore();
        }
    }

    return {
        searchTerm,
        visibleOptions,
        setSearchTerm,
        resetSearch,
        onViewportScroll,
    };
}
