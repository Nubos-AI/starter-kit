<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { useI18n } from '@/composables/useI18n';
import { provideSharedUiExtensions } from '@/composables/useUiExtensions';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const { t } = useI18n();

const page = usePage();

provideSharedUiExtensions();

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="t(title)" :description="t(description)">
        <slot />
        <UiExtensionPoint :name="`pages.${page.component}.after`" />
    </AuthLayout>
</template>
