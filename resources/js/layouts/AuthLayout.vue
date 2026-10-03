<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const { t } = useI18n();

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="t(title)" :description="t(description)">
        <slot />
    </AuthLayout>
</template>
