<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { home } from '@/routes';
import type { NavNode, NavSection } from '@/shared/types';
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from '@/shared/ui/primitives/sidebar';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';
import AppLogo from './AppLogo.vue';
import NavDrillDown from './NavDrillDown.vue';
import NavUser from './NavUser.vue';

const page = usePage();

const mainSections = computed<NavSection[]>(() => page.props.navigation?.main ?? []);

const configurationNodes = computed<NavNode[]>(() => page.props.navigation?.configuration ?? []);
</script>

<template>
  <Sidebar collapsible="icon" variant="sidebar" class="[&_[data-sidebar=menu-button]]:text-base">
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
      <NavDrillDown :main-sections="mainSections" :configuration="configurationNodes" />
      <UiExtensionPoint name="sidebar.after" />
    </SidebarContent>

    <SidebarFooter>
      <UiExtensionPoint name="sidebar.footer" />
      <NavUser />
    </SidebarFooter>
  </Sidebar>
  <slot />
</template>
