<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { Button } from '@/shared/ui/primitives/button';
import { CardTitle } from '@/shared/ui/primitives/card';
import { CollapsibleTrigger } from '@/shared/ui/primitives/collapsible';

defineOptions({ inheritAttrs: false });

defineProps<{
  title: string;
  open: boolean;
}>();
</script>

<template>
  <CardTitle>
    <CollapsibleTrigger as-child>
      <Button
        v-bind="$attrs"
        variant="ghost"
        class="h-auto w-full justify-between p-0 text-[length:inherit] leading-none font-semibold hover:bg-transparent has-[>svg]:px-0"
      >
        {{ title }}
        <ChevronDown
          class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 ease-out"
          :class="open ? '' : '-rotate-90'"
          aria-hidden="true"
        />
      </Button>
    </CollapsibleTrigger>
  </CardTitle>
</template>
