<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { IconActionButton } from '@/components/ui/icon-action-button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
    }>(),
    { placeholder: undefined },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    clear: [];
}>();

function onInput(value: string | number): void {
    emit('update:modelValue', String(value));
}
</script>

<template>
    <div class="flex items-center gap-2">
        <div class="relative flex-1">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                :model-value="props.modelValue"
                type="search"
                :aria-label="
                    t('i18n.components.engine.record_search_bar.search_records')
                "
                data-record-search-input
                :placeholder="
                    props.placeholder ??
                    t(
                        'i18n.components.engine.record_search_bar.search_all_fields',
                    )
                "
                class="pl-9"
                @update:model-value="onInput"
            />
        </div>

        <IconActionButton
            v-if="props.modelValue !== ''"
            :icon="X"
            :label="t('i18n.components.engine.record_search_bar.clear_search')"
            test-id="record-search-clear"
            class="shrink-0"
            @click="emit('clear')"
        />
    </div>
</template>
