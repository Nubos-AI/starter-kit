<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();
</script>

<template>
    <div class="flex min-w-0 items-center gap-3" data-app-logo>
        <div class="flex size-8 shrink-0 items-center justify-center">
            <AppLogoIcon class="block w-full" data-app-logo-image />
        </div>
        <span
            class="relative top-px truncate text-sm leading-none font-semibold group-data-[collapsible=icon]:hidden"
            data-app-logo-name
            >{{ t('i18n.components.app_logo.nubos_starterkit') }}</span
        >
    </div>
</template>
