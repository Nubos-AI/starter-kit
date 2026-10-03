<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{ class?: HTMLAttributes["class"] }>()
</script>

<template>
  <div
    data-command-empty
    data-slot="command-empty"
    role="presentation"
    :class="cn('text-muted-foreground py-6 text-center text-sm', props.class)"
  >
    <slot />
  </div>
</template>
