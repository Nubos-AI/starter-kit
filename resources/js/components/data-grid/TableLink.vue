<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ href: string }>();
</script>

<template>
    <Link
        :href="href"
        class="text-link underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
    >
        <slot />
    </Link>
</template>
