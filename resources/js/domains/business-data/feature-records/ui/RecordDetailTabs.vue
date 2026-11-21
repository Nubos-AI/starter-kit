<script setup lang="ts">
import { ref } from 'vue';
import { RecordNotesPanel } from '@/domains/business-data/ui-notes';
import { RecordFilesPanel } from '@/domains/business-data/ui-record-collaboration';
import { RecordActivitiesPanel } from '@/domains/business-data/ui-record-collaboration';
import { useI18n } from '@/shared/composables/useI18n';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/ui/primitives/tabs';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';
import RecordRemindersPanel from '../../../../shared/reminders/ui/RecordRemindersPanel.vue';
import { RECORD_DETAIL_TABS } from '../utils/recordDetailTabs';

const props = withDefaults(
  defineProps<{
    recordId: string;
    readonly?: boolean;
  }>(),
  { readonly: false },
);

const emit = defineEmits<{ changed: [] }>();

const { t } = useI18n();

const activeTab = ref<string>(RECORD_DETAIL_TABS[0].value);
const hasSelectedTab = ref(false);

function selectDefaultTab(value: string): void {
  if (!hasSelectedTab.value) {
    activeTab.value = value;
  }
}

function onTabChange(value: string | number): void {
  hasSelectedTab.value = true;
  activeTab.value = String(value);
}
</script>

<template>
  <Tabs
    extension-point="tabs.record-detail-tabs"
    :extension-context="{ ...$props, onChanged: () => emit('changed') }"
    :model-value="activeTab"
    data-record-detail-tabs
    class="flex flex-col gap-4"
    @update:model-value="onTabChange"
  >
    <TabsList overflow class="w-full">
      <UiExtensionPoint name="tabs.record-detail-tabs.triggers.before" :context="{ ...$props, selectDefaultTab }" />
      <TabsTrigger
        v-for="tab in RECORD_DETAIL_TABS"
        :key="tab.value"
        :value="tab.value"
        :data-record-detail-tab="tab.value"
      >
        {{ t(tab.label) }}
      </TabsTrigger>
    </TabsList>

    <TabsContent value="notes">
      <RecordNotesPanel :record-id="props.recordId" :readonly="props.readonly" @changed="emit('changed')" />
    </TabsContent>

    <TabsContent value="activity">
      <RecordActivitiesPanel :record-id="props.recordId" :readonly="props.readonly" @changed="emit('changed')" />
    </TabsContent>

    <TabsContent value="reminders">
      <RecordRemindersPanel :record-id="props.recordId" :readonly="props.readonly" @changed="emit('changed')" />
    </TabsContent>

    <TabsContent value="files">
      <RecordFilesPanel :record-id="props.recordId" :readonly="props.readonly" @changed="emit('changed')" />
    </TabsContent>
  </Tabs>
</template>
