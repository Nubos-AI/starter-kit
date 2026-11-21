<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useI18n } from '@/shared/composables/useI18n';
import { provideSharedUiExtensions } from '@/shared/composables/useUiExtensions';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';
import AuthLayout from './AuthSimpleLayout.vue';

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
