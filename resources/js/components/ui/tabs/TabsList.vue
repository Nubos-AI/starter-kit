<script setup lang="ts">
import { computed, inject } from "vue"
import UiExtensionPoint from "@/components/modules/UiExtensionPoint.vue"
import { tabExtensionKey } from "@/components/modules/tabExtensions"
import type { TabExtensionContext } from "@/components/modules/tabExtensions"
import type { TabsListProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsList, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TabsListProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
const forwarded = useForwardProps(delegatedProps)
const extension = inject(tabExtensionKey, computed<TabExtensionContext>(() => ({})))
</script>

<template>
  <TabsList
    data-slot="tabs-list"
    v-bind="forwarded"
    :class="cn(
      'bg-muted text-muted-foreground inline-flex h-8 w-fit items-center justify-center rounded-lg p-[3px]',
      props.class,
    )"
  >
    <slot />
    <UiExtensionPoint v-if="extension.point" :name="`${extension.point}.triggers`" :context="extension.context" />
  </TabsList>
</template>
