<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();
import type { HTMLAttributes } from "vue"
import { Loader2Icon } from "@lucide/vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <Loader2Icon
    role="status"
    :aria-label="t('i18n.components.ui.spinner.spinner.loading')"
    :class="cn('size-4 animate-spin', props.class)"
  />
</template>
