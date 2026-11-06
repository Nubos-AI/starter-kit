<script setup lang="ts">
import type { ComboboxInputProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Search } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import { ComboboxInput, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<ComboboxInputProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<{ "update:modelValue": [value: string] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <div
    data-slot="command-input-wrapper"
    class="flex h-12 items-center gap-2 border-b px-4"
  >
    <Search class="size-4 shrink-0 opacity-50" />
    <ComboboxInput
      data-command-input
      data-slot="command-input"
      auto-focus
      v-bind="{ ...forwarded, ...$attrs }"
      :class="
        cn(
          'placeholder:text-muted-foreground flex h-11 w-full rounded-md bg-transparent py-3 text-sm outline-hidden disabled:cursor-not-allowed disabled:opacity-50',
          props.class,
        )
      "
    />
  </div>
</template>
