<script setup lang="ts">
import { ref } from 'vue';
import RecordActivitiesPanel from '@/components/engine/activities/RecordActivitiesPanel.vue';
import RecordFilesPanel from '@/components/engine/files/RecordFilesPanel.vue';
import RecordNotesPanel from '@/components/engine/notes/RecordNotesPanel.vue';
import RecordRemindersPanel from '@/components/engine/reminders/RecordRemindersPanel.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { RECORD_DETAIL_TABS } from '@/lib/recordDetailTabs';

const props = withDefaults(
    defineProps<{
        recordId: string;
        readonly?: boolean;
    }>(),
    { readonly: false },
);

const emit = defineEmits<{ changed: [] }>();

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
        <TabsList class="w-full">
            <UiExtensionPoint
                name="tabs.record-detail-tabs.triggers.before"
                :context="{ ...$props, selectDefaultTab }"
            />
            <TabsTrigger
                v-for="tab in RECORD_DETAIL_TABS"
                :key="tab.value"
                :value="tab.value"
                :data-record-detail-tab="tab.value"
            >
                {{ tab.label }}
            </TabsTrigger>
        </TabsList>

        <TabsContent value="notes">
            <RecordNotesPanel
                :record-id="props.recordId"
                :readonly="props.readonly"
                @changed="emit('changed')"
            />
        </TabsContent>

        <TabsContent value="activity">
            <RecordActivitiesPanel
                :record-id="props.recordId"
                :readonly="props.readonly"
                @changed="emit('changed')"
            />
        </TabsContent>

        <TabsContent value="reminders">
            <RecordRemindersPanel
                :record-id="props.recordId"
                :readonly="props.readonly"
                @changed="emit('changed')"
            />
        </TabsContent>

        <TabsContent value="files">
            <RecordFilesPanel
                :record-id="props.recordId"
                :readonly="props.readonly"
                @changed="emit('changed')"
            />
        </TabsContent>
    </Tabs>
</template>
