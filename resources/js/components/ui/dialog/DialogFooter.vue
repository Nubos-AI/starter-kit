<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();
import type { HTMLAttributes } from "vue"
import { DialogClose } from "reka-ui"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'

const props = withDefaults(defineProps<{
  class?: HTMLAttributes["class"]
  showCloseButton?: boolean
}>(), {
  showCloseButton: false,
})
</script>

<template>
  <div
    data-slot="dialog-footer"
    :class="cn('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', props.class)"
  >
    <slot />
    <DialogClose v-if="showCloseButton" as-child>
      <Button variant="outline"> {{ t('i18n.components.ui.dialog.dialog_footer.close') }} </Button>
    </DialogClose>
  </div>
</template>
