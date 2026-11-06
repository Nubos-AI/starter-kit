<script setup lang="ts">
import SectionNavItem from '@/components/SectionNavItem.vue';
import type { SectionNavEntry } from '@/types/navigation';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    entries: SectionNavEntry[];
    label: string;
}>();

const active = defineModel<string | null>({ default: null });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-6 lg:flex-row lg:gap-8">
        <aside v-if="props.entries.length > 0" class="lg:w-56 lg:shrink-0">
            <nav
                v-bind="$attrs"
                class="flex gap-1 overflow-x-auto lg:sticky lg:top-6 lg:flex-col lg:overflow-visible"
                :aria-label="props.label"
                data-section-nav
            >
                <SectionNavItem
                    v-for="entry in props.entries"
                    :key="entry.key"
                    :entry="entry"
                    :selected="active === entry.key"
                    @select="active = $event"
                />
                <slot name="append" />
            </nav>
        </aside>

        <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-6 overflow-y-auto">
            <slot />
        </div>
    </div>
</template>
