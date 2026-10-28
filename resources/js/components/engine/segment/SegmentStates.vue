<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import CreateButton from '@/components/CreateButton.vue';
import EmptyState from '@/components/engine/state/EmptyState.vue';
import ErrorState from '@/components/engine/state/ErrorState.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

export type SegmentStateKind = 'loading' | 'error' | 'empty' | 'ready';

export type SegmentEmptyKind = 'no-segments' | 'empty-static';

const props = withDefaults(
    defineProps<{
        state: SegmentStateKind;
        emptyKind?: SegmentEmptyKind;
        errorMessage?: string;
    }>(),
    {
        emptyKind: 'no-segments',
        errorMessage: undefined,
    },
);

const emit = defineEmits<{
    retry: [];
    create: [];
}>();

interface EmptyContent {
    icon: Component;
    title: string;
    description: string;
    cta: boolean;
}

const emptyContent = computed<EmptyContent>(() => {
    if (props.emptyKind === 'empty-static') {
        return {
            icon: Inbox,
            title: t(
                'i18n.components.engine.segment.segment_states.empty_static_list',
            ),
            description: t(
                'i18n.components.engine.segment.segment_states.this_list_has_no_entries_yet',
            ),
            cta: false,
        };
    }

    return {
        icon: Inbox,
        title: t(
            'i18n.components.engine.segment.segment_states.no_segments_yet',
        ),
        description: t(
            'i18n.components.engine.segment.segment_states.create_your_first_segment_to_group_records',
        ),
        cta: true,
    };
});
</script>

<template>
    <div v-if="state !== 'ready'">
        <Skeleton
            v-if="state === 'loading'"
            class="h-9 w-full"
            aria-hidden="true"
        />

        <div
            v-else-if="state === 'error'"
            data-segment-error
            role="alert"
            aria-live="assertive"
            class="flex flex-col gap-3"
        >
            <ErrorState
                :title="
                    t(
                        'i18n.components.engine.segment.segment_states.segments_could_not_be_loaded',
                    )
                "
                default-message="Die Segmente konnten nicht geladen werden. Bitte versuchen Sie es erneut."
                :message="errorMessage"
                @retry="emit('retry')"
            />
        </div>

        <div
            v-else
            data-segment-empty
            class="flex flex-col items-center gap-3 py-6 text-center"
        >
            <EmptyState
                :icon="emptyContent.icon"
                :title="emptyContent.title"
                :description="emptyContent.description"
                heading-level="h3"
                icon-class="size-8 text-muted-foreground"
                title-class="text-sm font-semibold"
            >
                <CreateButton
                    v-if="emptyContent.cta"
                    size="sm"
                    :label="
                        t(
                            'i18n.components.engine.segment.segment_states.create_segment',
                        )
                    "
                    @click="emit('create')"
                />
            </EmptyState>
        </div>
    </div>
</template>
