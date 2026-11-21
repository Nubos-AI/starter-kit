<script setup lang="ts">
import { computed, inject, onBeforeUnmount } from 'vue';
import type { FunctionalComponent } from 'vue';
import { sectionNavKey } from '@/shared/lib/sectionNav';
import { Card, CardContent } from '@/shared/ui/primitives/card';

const props = withDefaults(
  defineProps<{
    section: string;
    actions?: boolean;
    bare?: boolean;
  }>(),
  { actions: true, bare: false },
);

const navigation = inject(sectionNavKey, null);

const unregister = navigation?.register(props.section, () => props.actions && !props.bare);

onBeforeUnmount(() => unregister?.());

const isShown = computed<boolean>(
  () => navigation === null || !navigation.isNavigable.value || navigation.active.value === props.section,
);

const showsActions = computed<boolean>(
  () => props.actions && !props.bare && navigation !== null && navigation.hasActions(props.section),
);

const SectionActions: FunctionalComponent = () => navigation?.actions()?.() ?? [];
</script>

<template>
  <div v-if="props.bare" v-show="isShown" class="flex flex-col gap-6" :data-section-panel="props.section">
    <slot :shown="isShown" />
  </div>
  <Card v-else v-show="isShown" :data-section-panel="props.section">
    <CardContent class="flex flex-col gap-6">
      <slot :shown="isShown" />
      <SectionActions v-if="showsActions" />
    </CardContent>
  </Card>
</template>
