<script setup lang="ts">
import { computed, nextTick, provide, ref, useSlots } from 'vue';
import { sectionNavKey } from '@/shared/lib/sectionNav';
import type { SectionNavEntry } from '@/shared/types/navigation';
import SectionNavItem from '@/shared/ui/SectionNavItem.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
  entries: SectionNavEntry[];
  label: string;
}>();

const active = defineModel<string | null>({ default: null });

const slots = useSlots();

const isNavigable = computed<boolean>(() => props.entries.length > 0);

const panels = ref<{ section: string; offersActions: () => boolean }[]>([]);

const lastActionPanel = computed<string | null>(
  () => panels.value.filter((panel) => panel.offersActions()).at(-1)?.section ?? null,
);

function register(section: string, offersActions: () => boolean): () => void {
  const panel = { section, offersActions };

  panels.value = [...panels.value, panel];

  return () => {
    panels.value = panels.value.filter((entry) => entry !== panel);
  };
}

function hasActions(section: string): boolean {
  if (slots.actions === undefined) {
    return false;
  }

  return isNavigable.value ? active.value === section : lastActionPanel.value === section;
}

provide(sectionNavKey, {
  active,
  isNavigable,
  actions: () => slots.actions,
  hasActions,
  register,
});

type ValidatedField = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

let invalidFields: { section: string; field: ValidatedField }[] = [];

function isValidatedField(target: EventTarget | null): target is ValidatedField {
  return (
    target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement
  );
}

async function revealInvalid(event: Event): Promise<void> {
  const field = event.target;

  if (!isNavigable.value || !isValidatedField(field)) {
    return;
  }

  const section = field.closest('[data-section-panel]')?.getAttribute('data-section-panel');

  if (section === undefined || section === null) {
    return;
  }

  invalidFields.push({ section, field });

  if (invalidFields.length > 1) {
    return;
  }

  await nextTick();

  const reported = invalidFields;

  invalidFields = [];

  if (reported.some((entry) => entry.section === active.value)) {
    return;
  }

  active.value = reported[0].section;

  await nextTick();

  reported[0].field.reportValidity();
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-6 lg:flex-row lg:gap-8" @invalid.capture="revealInvalid">
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
