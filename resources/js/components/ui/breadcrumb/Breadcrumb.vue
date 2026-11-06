<script lang="ts" setup>
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

import type { HTMLAttributes } from "vue"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <nav
    :aria-label="t('i18n.components.ui.breadcrumb.breadcrumb.breadcrumb')"
    data-slot="breadcrumb"
    :class="props.class"
  >
    <slot />
  </nav>
</template>
