<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import type { SelectOption } from '@/shared/types/ui';
import { Combobox } from '@/shared/ui/primitives/combobox';
import RecordPeopleTrigger from './RecordPeopleTrigger.vue';

const { t } = useI18n();

const EMPTY_LABEL = t('i18n.components.engine.records.record_owner_menu.no_owner');

const UNKNOWN_LABEL = t('i18n.components.engine.records.record_owner_menu.current_owner');

const props = withDefaults(
  defineProps<{
    options: SelectOption[];
    disabled?: boolean;
  }>(),
  {
    disabled: false,
  },
);

const ownerId = defineModel<string | null>({ required: true });

const selected = computed<SelectOption | undefined>(() =>
  props.options.find((option) => option.value === ownerId.value),
);

const label = computed<string>(() => {
  if (ownerId.value === null) {
    return EMPTY_LABEL;
  }

  return selected.value?.label ?? UNKNOWN_LABEL;
});

const avatarName = computed<string | null>(() => selected.value?.avatar?.name ?? null);
</script>

<template>
  <Combobox
    v-model="ownerId"
    :options="props.options"
    :disabled="props.disabled"
    :placeholder="t('i18n.components.engine.records.record_owner_menu.select_person')"
    :search-placeholder="t('i18n.components.engine.records.record_owner_menu.search_people')"
    :empty-label="t('i18n.components.engine.records.record_owner_menu.there_are_no_people_to_select')"
    :aria-label="t('i18n.components.engine.records.record_owner_menu.owner')"
    data-record-owner-menu
  >
    <template #trigger>
      <RecordPeopleTrigger
        :caption="t('i18n.components.engine.records.record_owner_menu.owner')"
        :label="label"
        :avatar-name="avatarName"
        :disabled="props.disabled"
      />
    </template>
  </Combobox>
</template>
