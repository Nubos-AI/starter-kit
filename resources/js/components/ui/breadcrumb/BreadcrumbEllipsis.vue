<script lang="ts" setup>
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

import type { HTMLAttributes } from "vue"
import { MoreHorizontal } from "@lucide/vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <span
    data-slot="breadcrumb-ellipsis"
    role="presentation"
    aria-hidden="true"
    :class="cn('flex size-9 items-center justify-center', props.class)"
  >
    <slot>
      <MoreHorizontal class="size-4" />
    </slot>
    <span class="sr-only">{{ t('i18n.components.ui.breadcrumb.breadcrumb_ellipsis.more') }}</span>
  </span>
</template>
