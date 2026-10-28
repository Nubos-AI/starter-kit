<script lang="ts" setup>
import { useI18n } from '@/composables/useI18n';

import type { ToasterProps } from "vue-sonner"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "@lucide/vue"
import { Toaster as Sonner } from "vue-sonner"
import { computed } from "vue"
import { cn } from "@/lib/utils"

import 'vue-sonner/style.css';

const { t } = useI18n();

const TOAST_DURATION_MS = 4000

const TOAST_WIDTH = '460px'

const TOAST_CLASSES = {
  toast: 'group',
  title: 'text-sm leading-snug font-semibold',
  description: 'text-sm leading-snug',
  icon: 'shrink-0',
  closeButton: '',
}

const props = withDefaults(defineProps<ToasterProps>(), {
  position: 'bottom-left',
  richColors: true,
  closeButton: true,
  closeButtonPosition: 'top-right',
  duration: TOAST_DURATION_MS,
})

const toastOptions = computed(() => ({
  classes: TOAST_CLASSES,
  closeButtonAriaLabel: t('i18n.components.ui.sonner.sonner.close'),
  ...props.toastOptions,
}))
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{
      '--normal-bg': 'var(--ds-surface-overlay)',
      '--normal-text': 'var(--ds-text)',
      '--normal-border': 'var(--ds-border)',
      '--success-bg': 'var(--ds-bg-accent-lime-subtlest)',
      '--success-border': 'var(--ds-border-accent-lime)',
      '--success-text': 'var(--ds-text-accent-lime-bolder)',
      '--error-bg': 'var(--ds-bg-accent-red-subtlest)',
      '--error-border': 'var(--ds-border-accent-red)',
      '--error-text': 'var(--ds-text-accent-red-bolder)',
      '--warning-bg': 'var(--ds-bg-accent-orange-subtlest)',
      '--warning-border': 'var(--ds-border-accent-orange)',
      '--warning-text': 'var(--ds-text-accent-orange-bolder)',
      '--info-bg': 'var(--ds-bg-accent-blue-subtlest)',
      '--info-border': 'var(--ds-border-accent-blue)',
      '--info-text': 'var(--ds-text-accent-blue-bolder)',
      '--border-radius': 'var(--ds-radius-large)',
      '--width': TOAST_WIDTH,
      '--toast-duration': `${props.duration}ms`,
    }"
    v-bind="props"
    :toast-options="toastOptions"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-4" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-4" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-3.5" />
    </template>
  </Sonner>
</template>
