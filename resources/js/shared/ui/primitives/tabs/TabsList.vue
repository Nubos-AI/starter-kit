<script setup lang="ts">
import { computed, inject, useTemplateRef } from "vue"
import { Check, ChevronDown } from "@lucide/vue"
import UiExtensionPoint from "@/shared/ui/UiExtensionPoint.vue"
import { tabExtensionKey } from "@/shared/ui/tabExtensions"
import type { TabExtensionContext } from "@/shared/ui/tabExtensions"
import { Button } from "@/shared/ui/primitives/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/shared/ui/primitives/dropdown-menu"
import { useI18n } from "@/shared/composables/useI18n"
import { useTabsOverflow } from "@/shared/composables/useTabsOverflow"
import type { TabsListProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsList, useForwardProps } from "reka-ui"
import { cn } from "@/shared/lib/utils"

const props = defineProps<TabsListProps & { class?: HTMLAttributes["class"]; overflow?: boolean }>()

const delegatedProps = reactiveOmit(props, "class", "overflow")
const forwarded = useForwardProps(delegatedProps)
const extension = inject(tabExtensionKey, computed<TabExtensionContext>(() => ({})))
const { t } = useI18n()
const container = useTemplateRef<HTMLElement>("container")
const { activeValue, hiddenTabs, isHiddenTabActive, selectTab } = useTabsOverflow(container, () => props.overflow === true)
const hasHiddenTabs = computed<boolean>(() => hiddenTabs.value.length > 0)
const listClass = "bg-muted text-muted-foreground inline-flex h-10 w-fit items-center justify-center rounded-lg p-[3px]"
</script>

<template>
  <div
    v-if="overflow"
    ref="container"
    data-slot="tabs-list"
    data-tabs-overflow
    :class="cn(listClass, 'w-full gap-1', props.class)"
  >
    <TabsList
      v-bind="forwarded"
      class="flex h-full min-w-0 flex-1 items-center"
    >
      <slot />
      <UiExtensionPoint v-if="extension.point" :name="`${extension.point}.triggers`" :context="extension.context" />
    </TabsList>
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button
          type="button"
          size="icon-sm"
          :variant="isHiddenTabActive ? 'outline' : 'ghost'"
          data-tabs-overflow-trigger
          :data-state="isHiddenTabActive ? 'active' : 'inactive'"
          :aria-hidden="hasHiddenTabs ? undefined : 'true'"
          :tabindex="hasHiddenTabs ? undefined : -1"
          :aria-label="t('i18n.components.ui.tabs.tabs_list.more_tabs')"
          :title="t('i18n.components.ui.tabs.tabs_list.more_tabs')"
          :class="cn(!hasHiddenTabs && 'invisible absolute')"
        >
          <ChevronDown />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem
          v-for="tab in hiddenTabs"
          :key="tab.value"
          :disabled="tab.disabled"
          :data-tabs-overflow-item="tab.value"
          :data-state="tab.value === activeValue ? 'active' : 'inactive'"
          @select="selectTab(tab.value)"
        >
          <span class="flex-1">{{ tab.label }}</span>
          <Check v-if="tab.value === activeValue" />
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
  <TabsList
    v-else
    data-slot="tabs-list"
    v-bind="forwarded"
    :class="cn(listClass, props.class)"
  >
    <slot />
    <UiExtensionPoint v-if="extension.point" :name="`${extension.point}.triggers`" :context="extension.context" />
  </TabsList>
</template>
