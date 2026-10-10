<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import NavDrillDown from '@/components/NavDrillDown.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { home } from '@/routes';
import type { NavNode, NavSection } from '@/types';

const page = usePage();

const mainSections = computed<NavSection[]>(
    () => page.props.navigation?.main ?? [],
);

const configurationNodes = computed<NavNode[]>(
    () => page.props.navigation?.configuration ?? [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <UiExtensionPoint name="sidebar.header" />
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <UiExtensionPoint name="sidebar.before" />
            <NavDrillDown
                :main-sections="mainSections"
                :configuration="configurationNodes"
            />
            <UiExtensionPoint name="sidebar.after" />
        </SidebarContent>

        <SidebarFooter>
            <UiExtensionPoint name="sidebar.footer" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
