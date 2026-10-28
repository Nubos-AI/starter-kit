<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import { syncPreferences } from '@/composables/useUserPreferences';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
};

withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});

const page = usePage();

const isOpen =
    page.props.preferences?.settings.sidebarOpen ?? page.props.sidebarOpen;

watch(
    () => page.props.preferences,
    (document) => {
        if (document !== null && document !== undefined) {
            syncPreferences(document);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen">
        <slot />
    </SidebarProvider>
</template>
