<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { useTemplateRef } from 'vue';
import { useBreadcrumbTrail } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { SidebarTrigger } from '@/shared/ui/primitives/sidebar';
import { useTopEdgeReveal } from '../composables/useTopEdgeReveal';
import Breadcrumbs from './Breadcrumbs.vue';

const { t } = useI18n();

const { items, backHref } = useBreadcrumbTrail();

const header = useTemplateRef<HTMLElement>('header');

const { isHidden } = useTopEdgeReveal(header);
</script>

<template>
  <header
    ref="header"
    class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-2 border-b border-sidebar-border bg-background px-4 transition-[width,height,translate] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-10 md:px-6"
    :class="{ '-translate-y-full': isHidden }"
    :data-header-hidden="isHidden"
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
