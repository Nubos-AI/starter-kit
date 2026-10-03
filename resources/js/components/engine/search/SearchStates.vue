<script setup lang="ts">
import { SearchX } from '@lucide/vue';
import EmptyState from '@/components/engine/state/EmptyState.vue';
import ErrorState from '@/components/engine/state/ErrorState.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

export type SearchStateKind = 'loading' | 'error' | 'empty' | 'ready';

export type SearchEmptyKind = 'no-match';

withDefaults(
    defineProps<{
        state: SearchStateKind;
        emptyKind?: SearchEmptyKind;
        errorMessage?: string;
    }>(),
    {
        emptyKind: 'no-match',
        errorMessage: undefined,
    },
);

const emit = defineEmits<{
    retry: [];
    create: [];
}>();
</script>

<template>
    <div v-if="state !== 'ready'">
        <div
            v-if="state === 'loading'"
            class="flex flex-col gap-3 p-4"
            aria-hidden="true"
        >
            <Skeleton class="h-8 w-full" />
            <Skeleton v-for="row in 4" :key="row" class="h-6 w-full" />
        </div>

        <div
            v-else-if="state === 'error'"
            data-command-error
            role="alert"
            aria-live="assertive"
            class="flex flex-col gap-4 p-4"
        >
            <ErrorState
                :title="
                    t(
                        'i18n.components.engine.search.search_states.search_failed',
                    )
                "
                default-message="Die Suche konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut."
                :message="errorMessage"
                :retry-attrs="{ 'data-command-retry': '' }"
                @retry="emit('retry')"
            />
        </div>

        <div
            v-else
            data-command-empty
            class="flex flex-col items-center gap-3 p-6 text-center"
        >
            <EmptyState
                :icon="SearchX"
                :title="
                    t('i18n.components.engine.search.search_states.no_matches')
                "
                :description="
                    t(
                        'i18n.components.engine.search.search_states.no_results_were_found_for_your_search',
                    )
                "
                title-class="text-base font-semibold"
            />
        </div>
    </div>
</template>
