<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import CommandPalette from '@/components/engine/search/CommandPalette.vue';
import MaintenanceLockBanner from '@/components/maintenance/MaintenanceLockBanner.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import NotificationBell from '@/components/notifications/NotificationBell.vue';
import TeamSwitcher from '@/components/teams/TeamSwitcher.vue';
import TrashButton from '@/components/trash/TrashButton.vue';
import { Toaster } from '@/components/ui/sonner';
import { provideBreadcrumbTail } from '@/composables/useBreadcrumbs';
import { provideUiExtensions } from '@/composables/useUiExtensions';

const page = usePage();

const tenantContextKey = computed(() => page.props.contextKey ?? 'default');

provideBreadcrumbTail();
provideUiExtensions(
    computed(() => ({
        modules: page.props.uiModules ?? [],
        extensions: page.props.uiExtensions ?? [],
        options: page.props.uiOptions ?? {},
        overrides: page.props.uiExtensionOverrides ?? {},
        page: page.props,
    })),
);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
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
            <div
                :key="tenantContextKey"
                class="contents"
                :data-tenant-context="tenantContextKey"
            >
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
