<script setup lang="ts">
import type { ComboboxGroupProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { ComboboxGroup, useId } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<
  ComboboxGroupProps & { class?: HTMLAttributes["class"], heading?: string }
>()

const headingId = useId(undefined, "command-group-heading")
</script>

<template>
  <ComboboxGroup
    data-command-group
    data-slot="command-group"
    :aria-labelledby="heading ? headingId : undefined"
    :class="
      cn('text-foreground overflow-hidden p-1', props.class)
    "
  >
    <div
      v-if="heading"
      :id="headingId"
      data-command-group-heading
      data-slot="command-group-heading"
      class="text-muted-foreground px-2 py-1.5 text-xs font-medium"
    >
      {{ heading }}
    </div>
    <slot />
  </ComboboxGroup>
</template>
