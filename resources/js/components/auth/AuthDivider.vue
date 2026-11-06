<script setup lang="ts">
defineProps<{
    label: string;
}>();
</script>

<template>
    <div
        class="flex items-center gap-3 text-xs text-muted-foreground uppercase"
        data-auth-divider
    >
        <span class="h-px flex-1 bg-border" />
        {{ label }}
        <span class="h-px flex-1 bg-border" />
    </div>
</template>
