<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

const SUCCESS_CLASS = 'icon-success hover:icon-success-hovered';

const VARIANT_CLASSES: Record<string, string> = {
    default: 'icon-subtlest hover:icon-subtle',
    destructive: 'icon-danger hover:icon-danger-hovered',
    edit: SUCCESS_CLASS,
    success: SUCCESS_CLASS,
    warning: 'icon-warning hover:icon-warning-hovered',
    info: 'icon-information hover:icon-information-hovered',
};

const props = withDefaults(
    defineProps<{
        icon: Component;
        label: string;
        title?: string;
        variant?:
            | 'default'
            | 'destructive'
            | 'edit'
            | 'info'
            | 'success'
            | 'warning';
        href?: string;
        download?: boolean;
        type?: 'button' | 'submit';
        disabled?: boolean;
        testId?: string;
    }>(),
    {
        variant: 'default',
        type: 'button',
    },
);

const resolvedTitle = computed<string>(() => props.title ?? props.label);

const emit = defineEmits<{
    click: [];
}>();

const variantClass = computed<string>(
    () => VARIANT_CLASSES[props.variant],
);
</script>

<template>
    <Button
        v-if="href && download && !disabled"
        variant="plain"
        size="icon-sm"
        as-child
        :class="variantClass"
        :aria-label="label"
        :title="resolvedTitle"
        :data-testid="testId"
    >
        <a :href="href" download>
            <component :is="icon" class="size-4" />
        </a>
    </Button>

    <Button
        v-else-if="href && !disabled"
        variant="plain"
        size="icon-sm"
        as-child
        :class="variantClass"
        :aria-label="label"
        :title="resolvedTitle"
        :data-testid="testId"
    >
        <Link :href="href">
            <component :is="icon" class="size-4" />
        </Link>
    </Button>

    <Button
        v-else
        variant="plain"
        size="icon-sm"
        :type="type"
        :disabled="disabled"
        :class="variantClass"
        :aria-label="label"
        :title="resolvedTitle"
        :data-testid="testId"
        @click="emit('click')"
    >
        <component :is="icon" class="size-4" />
    </Button>
</template>
