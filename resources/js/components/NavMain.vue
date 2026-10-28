<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { useI18n } from '@/composables/useI18n';
import type { NavItem } from '@/types';

const { t } = useI18n();

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: undefined },
);

const emit = defineEmits<{
    drill: [key: string];
}>();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="label" class="h-auto pt-3 pb-1">{{
            label ?? t('i18n.components.nav_main.platform')
        }}</SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem
                v-for="item in items"
                :key="item.key ?? item.title"
            >
                <SidebarSeparator
                    v-if="item.isSeparator"
                    class="mx-0 my-1"
                    data-nav-separator
                />
                <SidebarMenuButton
                    v-else-if="item.hasChildren && item.key"
                    class="h-7"
                    :tooltip="item.title"
                    @click="emit('drill', item.key)"
                >
                    <component :is="item.icon" />
                    <span>{{ item.title }}</span>
                    <ChevronRight class="ml-auto" />
                </SidebarMenuButton>
                <SidebarMenuButton
                    v-else
                    class="h-7"
                    as-child
                    :is-active="item.isActive === true"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
