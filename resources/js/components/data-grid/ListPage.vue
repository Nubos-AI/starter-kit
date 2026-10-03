<script setup lang="ts">
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
</script>

<template>
    <div class="relative flex h-full min-h-0 flex-1 flex-col gap-6 px-4 py-3">
        <UiExtensionPoint name="lists.before" />
        <slot />
        <UiExtensionPoint name="lists.after" />
    </div>
</template>
