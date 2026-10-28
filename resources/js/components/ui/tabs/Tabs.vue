<script setup lang="ts">
import { computed, provide } from "vue"
import UiExtensionPoint from "@/components/modules/UiExtensionPoint.vue"
import { tabExtensionKey } from "@/components/modules/tabExtensions"
import type { TabsRootEmits, TabsRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsRoot, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TabsRootProps & { class?: HTMLAttributes["class"]; extensionPoint?: string; extensionContext?: object }>()
const emits = defineEmits<TabsRootEmits>()

const delegatedProps = reactiveOmit(props, "class", "extensionPoint", "extensionContext")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
provide(tabExtensionKey, computed(() => ({ point: props.extensionPoint, context: props.extensionContext })))
</script>

<template>
  <TabsRoot
    v-slot="slotProps"
    data-slot="tabs"
    v-bind="forwarded"
    :class="cn('flex flex-col gap-2', props.class)"
  >
    <slot v-bind="slotProps" />
    <UiExtensionPoint v-if="extensionPoint" :name="`${extensionPoint}.panels`" :context="{ ...extensionContext, ...slotProps }" />
  </TabsRoot>
</template>
