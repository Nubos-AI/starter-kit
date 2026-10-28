<script setup lang="ts">
import { Columns3, FilterX, Inbox, SearchX } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, getCurrentInstance } from 'vue';
import CreateButton from '@/components/CreateButton.vue';
import EmptyState from '@/components/engine/state/EmptyState.vue';
import ErrorState from '@/components/engine/state/ErrorState.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

export type RecordViewState = 'loading' | 'error' | 'empty' | 'ready';

export type RecordEmptyKind =
    | 'no-records'
    | 'empty-column'
    | 'no-filter-match'
    | 'no-axis-columns';

export type RecordSkeletonVariant = 'table' | 'kanban' | 'list';

const props = withDefaults(
    defineProps<{
        state: RecordViewState;
        emptyKind?: RecordEmptyKind;
        skeletonVariant?: RecordSkeletonVariant;
        errorMessage?: string;
        emptyTitle?: string;
        emptyDescription?: string;
        createLabel?: string;
        canCreate?: boolean;
    }>(),
    {
        emptyKind: 'no-records',
        skeletonVariant: 'table',
        errorMessage: undefined,
        emptyTitle: undefined,
        emptyDescription: undefined,
        createLabel: undefined,
        canCreate: true,
    },
);

const emit = defineEmits<{
    retry: [];
    create: [];
    'clear-filter': [];
}>();

const instance = getCurrentInstance();

const canCreate = computed<boolean>(
    () => props.canCreate && instance?.vnode.props?.onCreate !== undefined,
);

interface EmptyContent {
    icon: Component;
    title: string;
    description: string;
}

const emptyContent = computed<EmptyContent>(() => {
    const base = ((): EmptyContent => {
        switch (props.emptyKind) {
            case 'no-filter-match':
                return {
                    icon: SearchX,
                    title: t('i18n.components.engine.view_states.no_matches'),
                    description: t(
                        'i18n.components.engine.view_states.there_are_no_records_matching_the_current_filters',
                    ),
                };
            case 'empty-column':
                return {
                    icon: Inbox,
                    title: t('i18n.components.engine.view_states.empty_column'),
                    description: t(
                        'i18n.components.engine.view_states.there_are_no_records_in_this_column',
                    ),
                };
            case 'no-axis-columns':
                return {
                    icon: Columns3,
                    title: t(
                        'i18n.components.engine.view_states.no_columns_for_this_axis',
                    ),
                    description: t(
                        'i18n.components.engine.view_states.the_selected_axis_has_no_values_add_stages_or',
                    ),
                };
            default:
                return {
                    icon: Inbox,
                    title: t(
                        'i18n.components.engine.view_states.no_records_yet',
                    ),
                    description: t(
                        'i18n.components.engine.view_states.create_your_first_record_to_get_started',
                    ),
                };
        }
    })();

    return {
        icon: base.icon,
        title: props.emptyTitle ?? base.title,
        description: props.emptyDescription ?? base.description,
    };
});
</script>

<template>
    <div
        v-if="state !== 'ready'"
        class="absolute inset-0 overflow-auto bg-background"
    >
        <div
            v-if="state === 'loading'"
            class="flex h-full w-full flex-col gap-4 p-4"
            aria-hidden="true"
        >
            <div
                v-if="skeletonVariant === 'kanban'"
                class="flex h-full gap-4 overflow-hidden"
            >
                <div
                    v-for="column in 4"
                    :key="column"
                    class="flex w-72 shrink-0 flex-col gap-3"
                >
                    <Skeleton class="h-6 w-32" />
                    <Skeleton
                        v-for="card in 3"
                        :key="card"
                        class="h-24 w-full"
                    />
                </div>
            </div>
            <template v-else>
                <Skeleton class="h-9 w-full" />
                <Skeleton v-for="row in 8" :key="row" class="h-10 w-full" />
            </template>
        </div>

        <div
            v-else-if="state === 'error'"
            role="alert"
            class="flex h-full w-full items-center justify-center p-6"
        >
            <div class="flex w-full max-w-md flex-col gap-4">
                <ErrorState
                    :title="
                        t(
                            'i18n.components.engine.view_states.data_could_not_be_loaded',
                        )
                    "
                    default-message="Beim Laden ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut."
                    :message="errorMessage"
                    @retry="emit('retry')"
                />
            </div>
        </div>

        <div v-else class="flex h-full w-full items-center justify-center p-6">
            <div class="flex max-w-sm flex-col items-center gap-3 text-center">
                <EmptyState
                    :icon="emptyContent.icon"
                    :title="emptyContent.title"
                    :description="emptyContent.description"
                >
                    <CreateButton
                        v-if="emptyKind === 'no-records' && canCreate"
                        :label="
                            createLabel ??
                            t(
                                'i18n.components.engine.view_states.create_first_record',
                            )
                        "
                        @click="emit('create')"
                    />
                    <Button
                        v-else-if="emptyKind === 'no-filter-match'"
                        variant="outline"
                        @click="emit('clear-filter')"
                    >
                        <FilterX />
                        {{
                            t(
                                'i18n.components.engine.view_states.reset_filters',
                            )
                        }}
                    </Button>
                </EmptyState>
            </div>
        </div>
    </div>
</template>
