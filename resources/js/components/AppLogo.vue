<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();
</script>

<template>
    <div class="flex size-8 shrink-0 items-center justify-center">
        <AppLogoIcon class="w-full" data-app-logo-image />
    </div>
    <div class="ml-1 flex flex-1 items-center text-left text-sm">
        <span class="truncate leading-tight font-semibold" data-app-logo-name>{{
            t('i18n.components.app_logo.nubos_starterkit')
        }}</span>
    </div>
</template>
