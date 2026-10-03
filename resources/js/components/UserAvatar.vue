<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

const TONES = [
    'bg-accent-blue-subtler text-accent-blue-bolder',
    'bg-accent-green-subtler text-accent-green-bolder',
    'bg-accent-orange-subtler text-accent-orange-bolder',
    'bg-accent-purple-subtler text-accent-purple-bolder',
    'bg-accent-magenta-subtler text-accent-magenta-bolder',
    'bg-accent-teal-subtler text-accent-teal-bolder',
] as const;

const props = withDefaults(
    defineProps<{
        name: string;
        class?: string;
    }>(),
    { class: undefined },
);

const initials = computed<string>(() => getInitials(props.name));

const tone = computed<string>(() => {
    let hash = 0;

    for (const character of props.name) {
        hash = (hash * 31 + (character.codePointAt(0) ?? 0)) % 2147483647;
    }

    return TONES[hash % TONES.length];
});
</script>

<template>
    <Avatar :class="cn('size-6', props.class)" data-user-avatar>
        <AvatarFallback
            :class="cn('text-[0.625rem] font-medium', tone)"
            :title="props.name"
        >
            {{ initials }}
        </AvatarFallback>
    </Avatar>
</template>
