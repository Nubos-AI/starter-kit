<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        reason: string | null;
        variant?:
            | 'default'
            | 'destructive'
            | 'success'
            | 'create'
            | 'warning'
            | 'outline'
            | 'secondary'
            | 'ghost'
            | 'link';
        size?: 'default' | 'sm' | 'lg' | 'icon' | 'icon-sm' | 'icon-lg';
        type?: 'button' | 'submit';
        disabled?: boolean;
    }>(),
    {
        variant: 'default',
        size: 'default',
        type: 'button',
        disabled: false,
    },
);

const emit = defineEmits<{
    click: [];
}>();
</script>

<template>
    <Button
        v-if="props.reason === null"
        v-bind="$attrs"
        :variant="props.variant"
        :size="props.size"
        :type="props.type"
        :disabled="props.disabled"
        @click="emit('click')"
    >
        <slot />
    </Button>
    <TooltipProvider v-else :delay-duration="0">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    tabindex="0"
                    class="inline-flex"
                    data-reasoned-button-trigger
                >
                    <Button
                        v-bind="$attrs"
                        :variant="props.variant"
                        :size="props.size"
                        :type="props.type"
                        disabled
                    >
                        <slot />
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent data-reasoned-button-reason>
                {{ props.reason }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
