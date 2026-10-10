<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useBreadcrumbTrail } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const { items, backHref } = useBreadcrumbTrail();
</script>

<template>
    <header
        class="flex h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-10 md:px-3"
    >
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <Link
                v-if="backHref !== null"
                data-breadcrumb-back
                :href="backHref"
                :aria-label="t('i18n.components.app_sidebar_header.back')"
                class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <Breadcrumbs v-if="items.length > 1" :breadcrumbs="items" />
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <slot name="actions" />
        </div>
    </header>
</template>
