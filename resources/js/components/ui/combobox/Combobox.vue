<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { Check, ChevronDown, Search } from '@lucide/vue';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxInput,
    ComboboxItem,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
} from 'reka-ui';
import { computed, ref } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOptionList } from '@/composables/useOptionList';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        options: SelectOption[];
        id?: string;
        placeholder?: string;
        searchPlaceholder?: string;
        emptyLabel?: string;
        noResultsLabel?: string;
        ariaLabel?: string;
        disabled?: boolean;
        searchable?: boolean;
        serverSearch?: boolean;
    }>(),
    {
        id: undefined,
        placeholder: undefined,
        searchPlaceholder: undefined,
        emptyLabel: undefined,
        noResultsLabel: undefined,
        ariaLabel: undefined,
        disabled: false,
        searchable: true,
        serverSearch: false,
    },
);

const emit = defineEmits<{
    search: [term: string];
    loadMore: [];
}>();

const model = defineModel<string | null>({ required: true });

const open = ref<boolean>(false);

const selectedOption = computed<SelectOption | undefined>(() =>
    props.options.find((option) => option.value === model.value),
);

const { searchTerm, visibleOptions, setSearchTerm, resetSearch, onViewportScroll } =
    useOptionList({
        options: () => props.options,
        searchable: () => props.searchable,
        serverSearch: () => props.serverSearch,
        onSearch: (term) => emit('search', term),
        onLoadMore: () => emit('loadMore'),
    });

function onOpenChange(next: boolean): void {
    open.value = next;

    if (!next) {
        resetSearch();
    }
}

function select(option: SelectOption): void {
    if (option.disabled === true) {
        return;
    }

    model.value = option.value;
    onOpenChange(false);
}
</script>

<template>
    <ComboboxRoot
        :open="open"
        :disabled="disabled"
        ignore-filter
        data-combobox
        @update:open="onOpenChange"
    >
        <ComboboxAnchor as-child>
            <ComboboxTrigger as-child :aria-label="ariaLabel ?? t('i18n.components.ui.combobox.combobox.open_selection')">
                <slot name="trigger" :selected="selectedOption" :open="open">
                <Button
                    :id="props.id"
                    type="button"
                    variant="outline"
                    role="combobox"
                    :disabled="disabled"
                    :aria-label="ariaLabel"
                    class="h-8 w-full justify-between gap-2 border-input font-normal focus-visible:border-bold focus-visible:ring-0"
                    data-combobox-trigger
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <template v-if="selectedOption">
                            <UserAvatar
                                v-if="selectedOption.avatar"
                                :name="selectedOption.avatar.name"
                                class="size-5"
                            />
                            <span class="truncate">{{
                                selectedOption.label
                            }}</span>
                        </template>
                        <span v-else class="text-muted-foreground">
                            {{ (placeholder ?? t('i18n.components.ui.combobox.combobox.please_select')) }}
                        </span>
                    </span>
                    <ChevronDown class="size-4 shrink-0 opacity-50" />
                </Button>
                </slot>
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxPortal>
            <ComboboxContent
                position="popper"
                :side-offset="4"
                class="z-50 max-h-72 w-(--reka-combobox-trigger-width) min-w-64 overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-none"
            >
                <div
                    v-if="searchable"
                    class="flex h-9 items-center gap-2 border-b px-3"
                >
                    <Search class="size-4 shrink-0 opacity-50" />
                    <ComboboxInput
                        :model-value="searchTerm"
                        :display-value="() => searchTerm"
                        auto-focus
                        :placeholder="(searchPlaceholder ?? t('i18n.components.ui.combobox.combobox.search'))"
                        class="h-8 w-full bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                        data-combobox-search
                        @update:model-value="setSearchTerm(String($event))"
                    />
                </div>

                <ComboboxViewport
                    class="max-h-60 overflow-y-auto p-1"
                    data-combobox-viewport
                    @scroll="onViewportScroll"
                >
                    <ComboboxItem
                        v-for="option in visibleOptions"
                        :key="option.value"
                        :value="option.value"
                        :disabled="option.disabled === true"
                        :title="
                            option.disabled === true
                                ? option.disabledReason
                                : undefined
                        "
                        class="relative flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-disabled:pointer-events-none data-disabled:opacity-50 data-highlighted:bg-accent data-highlighted:text-accent-foreground"
                        data-combobox-item
                        @select="select(option)"
                    >
                        <UserAvatar
                            v-if="option.avatar"
                            :name="option.avatar.name"
                        />
                        <span class="flex min-w-0 flex-col">
                            <span class="truncate">{{ option.label }}</span>
                            <span
                                v-if="option.description"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ option.description }}
                            </span>
                        </span>
                        <Badge v-if="option.badge" variant="outline">
                            {{ option.badge }}
                        </Badge>
                        <Check
                            v-if="option.value === model"
                            class="ml-auto size-4 shrink-0"
                        />
                    </ComboboxItem>

                    <p
                        v-if="visibleOptions.length === 0"
                        class="px-2 py-1.5 text-sm text-muted-foreground"
                        data-combobox-empty
                    >
                        {{ options.length === 0 ? (emptyLabel ?? t('i18n.components.ui.combobox.combobox.no_options_available')) : (noResultsLabel ?? t('i18n.components.ui.combobox.combobox.no_matches')) }}
                    </p>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
