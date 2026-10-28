<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();
import type { DialogRootEmits, DialogRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  ComboboxRoot,
  DialogContent,
  DialogDescription,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<DialogRootProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<DialogRootEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DialogRoot v-bind="forwarded">
    <DialogPortal>
      <DialogOverlay
        data-slot="command-overlay"
        class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/50"
      />
      <DialogContent
        data-command-palette
        data-slot="command-dialog"
        :class="
          cn(
            'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[15%] left-[50%] z-50 w-full max-w-[calc(100%-2rem)] translate-x-[-50%] overflow-hidden rounded-xl border shadow-lg duration-200 sm:max-w-lg',
            props.class,
          )
        "
      >
        <DialogTitle class="sr-only"> {{ t('i18n.components.ui.command.command_dialog.global_search') }} </DialogTitle>
        <DialogDescription class="sr-only"> {{ t('i18n.components.ui.command.command_dialog.search_all_visible_object_types_navigate_with_the_arrow') }} </DialogDescription>

        <ComboboxRoot
          :open="true"
          :ignore-filter="true"
          :reset-search-term-on-blur="false"
          :reset-search-term-on-select="false"
          data-slot="command"
          class="flex size-full flex-col overflow-hidden"
        >
          <slot />
        </ComboboxRoot>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
