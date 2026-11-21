<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CommandPalette } from '@/domains/business-data/ui-record-field';
import { TrashButton } from '@/domains/business-data/ui-trash';
import { NotificationBell } from '@/domains/workflow/ui-notifications';
import { TeamSwitcher } from '@/domains/workspace-access/ui-teams';
import { MaintenanceLockBanner } from '@/platform/feature-maintenance';
import { provideBreadcrumbTail } from '@/shared/composables/useBreadcrumbs';
import { provideSharedUiExtensions } from '@/shared/composables/useUiExtensions';
import { Toaster } from '@/shared/ui/primitives/sonner';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';
import AppContent from '../ui/AppContent.vue';
import AppShell from '../ui/AppShell.vue';
import AppSidebar from '../ui/AppSidebar.vue';
import AppSidebarHeader from '../ui/AppSidebarHeader.vue';

const page = usePage();

const tenantContextKey = computed(() => page.props.contextKey ?? 'default');

provideBreadcrumbTail();
provideSharedUiExtensions();
</script>

<template>
  <AppShell variant="sidebar">
    <AppSidebar />
    <AppContent variant="sidebar" class="overflow-x-clip">
      <AppSidebarHeader>
        <template #actions>
          <UiExtensionPoint name="header.actions" />
          <TeamSwitcher />
          <NotificationBell v-if="page.props.currentTeam" />
          <TrashButton v-if="page.props.currentTeam" />
          <UiExtensionPoint name="header.actions.after" />
        </template>
      </AppSidebarHeader>
      <UiExtensionPoint name="content.before" />
      <MaintenanceLockBanner />
      <div :key="tenantContextKey" class="contents" :data-tenant-context="tenantContextKey">
        <UiExtensionPoint :name="`pages.${page.component}.before`" />
        <slot />
        <UiExtensionPoint :name="`pages.${page.component}.after`" />
      </div>
      <UiExtensionPoint name="content.after" />
    </AppContent>
    <CommandPalette v-if="page.props.currentTeam" />
    <Toaster />
  </AppShell>
</template>
