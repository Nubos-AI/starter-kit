<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';

const props = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="relative flex h-full min-h-0 flex-1 flex-col gap-6 p-3">
        <UiExtensionPoint name="lists.before" />
        <template v-if="props.title !== undefined">
            <Head :title="props.title" />
            <div
                class="flex items-start justify-between gap-4"
                data-list-page-header
            >
                <Heading
                    variant="small"
                    :title="props.title"
                    :description="props.description"
                />
                <slot name="actions" />
            </div>
        </template>
        <slot />
        <UiExtensionPoint name="lists.after" />
    </div>
</template>
