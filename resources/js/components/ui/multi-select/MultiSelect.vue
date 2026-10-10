<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { Check, ChevronDown, Search, X } from '@lucide/vue';
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
import { computed, ref, watch } from 'vue';
import type { MultiSelectOption } from '.';
import UserAvatar from '@/components/UserAvatar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOptionList } from '@/composables/useOptionList';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        options: MultiSelectOption[];
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

const model = defineModel<string[]>({ required: true });

const open = ref<boolean>(false);

const selectedValues = computed<string[]>(() =>
    Array.isArray(model.value) ? model.value : [],
);

const selectedOptions = computed<MultiSelectOption[]>(() =>
    props.options.filter((option) => selectedValues.value.includes(option.value)),
);

const comboboxValue = ref<string[]>([...selectedValues.value]);

watch(selectedValues, (next) => {
    if (open.value || sameValues(next, comboboxValue.value)) {
        return;
    }

    comboboxValue.value = [...next];
});

function sameValues(left: string[], right: string[]): boolean {
    return (
        left.length === right.length &&
        left.every((value, index) => value === right[index])
    );
}

const { searchTerm, visibleOptions, setSearchTerm, resetSearch, onViewportScroll } =
    useOptionList({
        options: () => props.options,
        searchable: () => props.searchable,
        serverSearch: () => props.serverSearch,
        onSearch: (term) => emit('search', term),
        onLoadMore: () => emit('loadMore'),
    });

function isSelected(option: MultiSelectOption): boolean {
    return selectedValues.value.includes(option.value);
}

function onOpenChange(next: boolean): void {
    open.value = next;

    if (!next) {
        resetSearch();
    }

    if (!sameValues(selectedValues.value, comboboxValue.value)) {
        comboboxValue.value = [...selectedValues.value];
    }
}

function toggle(option: MultiSelectOption, selected: boolean): void {
    if (option.disabled === true) {
        return;
    }

    model.value = selected
        ? [...new Set([...selectedValues.value, option.value])]
        : selectedValues.value.filter((value) => value !== option.value);
}
</script>

<template>
    <ComboboxRoot
        multiple
        ignore-filter
        :model-value="comboboxValue"
        :open="open"
        :disabled="disabled"
        data-multi-select
        @update:open="onOpenChange"
    >
        <ComboboxAnchor as-child>
            <ComboboxTrigger as-child :aria-label="ariaLabel ?? t('i18n.components.ui.multi_select.multi_select.open_selection')">
                <slot name="trigger" :selected="selectedOptions" :open="open">
                <Button
                    :id="props.id"
                    type="button"
                    variant="outline"
                    role="combobox"
                    :disabled="disabled"
                    :aria-label="ariaLabel"
                    class="h-auto min-h-8 w-full justify-between gap-2 border-input py-1 font-normal focus-visible:border-bold focus-visible:ring-0"
                    data-multi-select-trigger
                >
                    <span class="flex flex-wrap items-center gap-1">
                        <template v-if="selectedOptions.length > 0">
                            <Badge
                                v-for="option in selectedOptions"
                                :key="option.value"
                                variant="secondary"
                                class="gap-1"
                            >
                                <UserAvatar
                                    v-if="option.avatar"
                                    :name="option.avatar.name"
                                    class="size-4"
                                />
                                {{ option.label }}
                                <span
                                    v-if="option.disabled !== true"
                                    role="button"
                                    tabindex="-1"
                                    :aria-label="t('i18n.components.ui.multi_select.multi_select.remove', { value1: option.label })"
                                    class="cursor-pointer opacity-70 hover:opacity-100"
                                    @click.stop.prevent="toggle(option, false)"
                                >
                                    <X class="size-3" />
                                </span>
                            </Badge>
                        </template>
                        <span v-else class="text-muted-foreground">
                            {{ (placeholder ?? t('i18n.components.ui.multi_select.multi_select.please_select')) }}
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
                        :placeholder="(searchPlaceholder ?? t('i18n.components.ui.multi_select.multi_select.search'))"
                        class="h-8 w-full bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                        data-multi-select-search
                        @update:model-value="setSearchTerm(String($event))"
                    />
                </div>

                <ComboboxViewport
                    class="max-h-60 overflow-y-auto p-1"
                    data-multi-select-viewport
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
                        data-multi-select-item
                        @select.prevent="toggle(option, !isSelected(option))"
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
                            v-if="isSelected(option)"
                            class="ml-auto size-4 shrink-0"
                        />
                    </ComboboxItem>

                    <p
                        v-if="visibleOptions.length === 0"
                        class="px-2 py-1.5 text-sm text-muted-foreground"
                        data-multi-select-empty
                    >
                        {{ options.length === 0 ? (emptyLabel ?? t('i18n.components.ui.multi_select.multi_select.no_options_available')) : (noResultsLabel ?? t('i18n.components.ui.multi_select.multi_select.no_matches')) }}
                    </p>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
