<script setup lang="ts">
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';

type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <h2 class="text-2xl font-bold" :class="{ 'mb-1': variant === 'small' }">
            {{ title }}
        </h2>
        <p v-if="description" class="max-w-prose text-sm text-muted-foreground">
            {{ description }}
        </p>
        <UiExtensionPoint name="heading.actions" :context="$props" />
    </header>
</template>
