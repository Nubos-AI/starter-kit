<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue';
import type { Component } from 'vue';
import { useUiExtensions } from '@/composables/useUiExtensions';
import { moduleComponent } from '@/lib/modules';
import type { UiExtension } from '@/types/modules';

const props = withDefaults(
    defineProps<{
        name: string;
        context?: object;
    }>(),
    { context: () => ({}) },
);
const state = useUiExtensions();
const components = new Map<string, Component>();

function componentOf(extension: UiExtension): Component {
    const key = `${extension.module}:${extension.component}`;
    const cached = components.get(key);

    if (cached) {
        return cached;
    }

    const load = moduleComponent(extension.module, extension.component);
    const component = defineAsyncComponent(async () => (await load()).default);
    components.set(key, component);

    return component;
}

const extensions = computed(() =>
    state.value.extensions
        .map((extension) => ({
            ...extension,
            ...(state.value.overrides[extension.id] ?? {}),
        }))
        .filter(
            (extension) =>
                extension.enabled !== false && extension.point === props.name,
        )
        .sort(
            (first, second) =>
                first.order - second.order || first.id.localeCompare(second.id),
        )
        .map((extension) => ({
            ...extension,
            component: componentOf(extension),
        })),
);
</script>

<template>
    <div class="contents" :data-extension-point="name">
        <slot v-if="extensions.length === 0" />
        <component
            :is="extension.component"
            v-for="extension in extensions"
            :key="extension.id"
            v-bind="extension.props"
            :context="{ page: state.page, ...context }"
        />
    </div>
</template>
