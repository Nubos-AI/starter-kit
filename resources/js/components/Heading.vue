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
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-lg font-bold'
                    : 'text-2xl font-bold'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
        <UiExtensionPoint name="heading.actions" :context="$props" />
    </header>
</template>
