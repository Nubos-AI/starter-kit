<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { SectionNavEntry } from '@/types/navigation';

const props = withDefaults(
    defineProps<{
        entry: SectionNavEntry;
        selected?: boolean;
    }>(),
    { selected: false },
);

const emit = defineEmits<{
    select: [key: string];
}>();

const { isCurrentUrl } = useCurrentUrl();

const href = computed<NonNullable<SectionNavEntry['href']> | null>(
    () => props.entry.href ?? null,
);

const disabledReason = computed<string | null>(
    () => props.entry.disabledReason ?? null,
);

const isLink = computed<boolean>(() => href.value !== null);

const isDisabled = computed<boolean>(
    () => !isLink.value && disabledReason.value !== null,
);

const isActive = computed<boolean>(() =>
    href.value !== null ? isCurrentUrl(href.value) : props.selected,
);

const hasErrors = computed<boolean>(() => props.entry.hasErrors === true);

function select(): void {
    if (isLink.value || isDisabled.value) {
        return;
    }

    emit('select', props.entry.key);
}
</script>

<template>
    <Button
        :as="isLink ? Link : 'button'"
        :href="href ?? undefined"
        :type="isLink ? undefined : 'button'"
        variant="ghost"
        :class="[
            'shrink-0 justify-between gap-2 lg:w-full',
            { 'bg-muted': isActive },
        ]"
        :disabled="isDisabled"
        :title="isDisabled ? disabledReason : undefined"
        :aria-current="isActive ? 'page' : undefined"
        data-section
        :data-section-key="props.entry.key"
        :data-section-active="isActive ? '' : undefined"
        :data-section-disabled="isDisabled ? '' : undefined"
        :data-section-errors="hasErrors ? '' : undefined"
        @click="select"
    >
        <span>{{ props.entry.title }}</span>
        <span
            v-if="hasErrors"
            class="size-2 shrink-0 rounded-full bg-danger-bold"
            aria-hidden="true"
            data-section-error-dot
        />
    </Button>
</template>
