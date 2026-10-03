<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useMagicKeys } from '@vueuse/core';
import { computed, ref, useId, watch } from 'vue';
import QuickCreate from '@/components/engine/search/QuickCreate.vue';
import SearchStates from '@/components/engine/search/SearchStates.vue';
import type { SearchStateKind } from '@/components/engine/search/SearchStates.vue';
import { Button } from '@/components/ui/button';
import {
    CommandDialog,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { useGlobalSearch } from '@/composables/useGlobalSearch';
import { useI18n } from '@/composables/useI18n';
import { show } from '@/routes/engine/records';

const { t } = useI18n();

const open = ref<boolean>(false);
const createMode = ref<boolean>(false);
const resultsId = useId();
const { query, groups, loading, error, search, reset } = useGlobalSearch();

let restoreFocusTo: HTMLElement | null = null;

const showError = computed<boolean>(
    () => error.value !== null && !loading.value,
);

const showCreateTrigger = computed<boolean>(
    () => !createMode.value && query.value.trim() === '' && !showError.value,
);

function enterCreateMode(): void {
    createMode.value = true;
}

const showEmpty = computed<boolean>(
    () =>
        query.value.trim() !== '' &&
        !loading.value &&
        error.value === null &&
        groups.value.length === 0,
);

const searchState = computed<SearchStateKind>(() => {
    if (error.value !== null && !loading.value) {
        return 'error';
    }

    if (loading.value && error.value === null) {
        return 'loading';
    }

    if (showEmpty.value) {
        return 'empty';
    }

    return 'ready';
});

function onRetry(): void {
    search(query.value);
}

function isTextEntryActive(): boolean {
    const active = document.activeElement as HTMLElement | null;

    if (active === null) {
        return false;
    }

    if (active.closest('.ag-cell-inline-editing') !== null) {
        return true;
    }

    const tag = active.tagName;

    if (tag === 'INPUT' || tag === 'TEXTAREA') {
        return true;
    }

    return active.isContentEditable;
}

function openPalette(): void {
    restoreFocusTo = document.activeElement as HTMLElement | null;
    open.value = true;
}

function closePalette(): void {
    open.value = false;
}

useMagicKeys({
    passive: false,
    onEventFired(event) {
        if (event.type !== 'keydown') {
            return;
        }

        const isCommandKey =
            event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey);

        if (!isCommandKey) {
            return;
        }

        if (open.value) {
            event.preventDefault();
            closePalette();

            return;
        }

        if (isTextEntryActive()) {
            return;
        }

        event.preventDefault();
        openPalette();
    },
});

watch(open, (isOpen, wasOpen) => {
    if (isOpen || !wasOpen) {
        return;
    }

    query.value = '';
    createMode.value = false;
    reset();

    const target = restoreFocusTo;
    restoreFocusTo = null;

    if (target !== null && typeof target.focus === 'function') {
        target.focus();
    }
});

function onSelect(recordId: string): void {
    closePalette();
    router.visit(show.url({ record: recordId }));
}
</script>

<template>
    <CommandDialog v-model:open="open">
        <CommandInput
            v-model="query"
            :aria-label="
                t('i18n.components.engine.search.command_palette.global_search')
            "
            :aria-controls="resultsId"
            :placeholder="
                t(
                    'i18n.components.engine.search.command_palette.search_objects',
                )
            "
        />
        <SearchStates
            :state="searchState"
            :error-message="error ?? undefined"
            @retry="onRetry"
        />
        <QuickCreate v-if="createMode" />
        <CommandList
            v-else
            :id="resultsId"
            :aria-label="
                t(
                    'i18n.components.engine.search.command_palette.search_results',
                )
            "
        >
            <Button
                v-if="showCreateTrigger"
                type="button"
                variant="ghost"
                data-command-create
                class="h-auto w-full cursor-default justify-start rounded-sm px-3 py-2 text-left font-medium text-foreground"
                @click="enterCreateMode"
            >
                {{
                    t(
                        'i18n.components.engine.search.command_palette.create_new',
                    )
                }}
            </Button>
            <CommandGroup
                v-for="group in groups"
                :key="group.type"
                :heading="group.label"
            >
                <CommandItem
                    v-for="record in group.records"
                    :key="record.id"
                    :value="record.id"
                    :data-record-id="record.id"
                    @select="onSelect(record.id)"
                >
                    {{ record.title }}
                </CommandItem>
            </CommandGroup>
        </CommandList>
    </CommandDialog>
</template>
