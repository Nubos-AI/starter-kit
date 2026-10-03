<script setup lang="ts">
import { ChevronDown, User } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import UserAvatar from '@/components/UserAvatar.vue';

const props = withDefaults(
    defineProps<{
        caption: string;
        label: string;
        avatarName?: string | null;
        disabled?: boolean;
    }>(),
    {
        avatarName: null,
        disabled: false,
    },
);
</script>

<template>
    <Button
        type="button"
        variant="ghost"
        :disabled="props.disabled"
        :aria-label="`${props.caption}: ${props.label}`"
        class="h-auto gap-2 px-2 py-1"
        data-people-trigger
    >
        <UserAvatar
            v-if="props.avatarName"
            :name="props.avatarName"
            class="size-7"
        />
        <span
            v-else
            data-people-trigger-placeholder
            class="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
        >
            <User class="size-4" aria-hidden="true" />
        </span>

        <span class="flex min-w-0 flex-col items-start">
            <span
                data-people-trigger-label
                class="max-w-40 truncate font-semibold"
            >
                {{ props.label }}
            </span>
            <span
                data-people-trigger-caption
                class="text-xs font-normal text-muted-foreground"
            >
                {{ props.caption }}
            </span>
        </span>

        <ChevronDown
            class="size-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
    </Button>
</template>
