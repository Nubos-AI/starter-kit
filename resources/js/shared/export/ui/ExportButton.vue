<script setup lang="ts">
import { ref } from 'vue';
import type { RecordObjectType } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import type { SnapshotProvider } from '@/shared/records/composables/useRecordSelection';
import type { FieldDefinition } from '@/shared/types/fields';
import { Button } from '@/shared/ui/primitives/button';
import ExportDialog from './ExportDialog.vue';

const { t } = useI18n();

const props = defineProps<{
  objectType: RecordObjectType;
  fieldDefinitions: FieldDefinition[];
  snapshotProvider: SnapshotProvider;
  segmentId?: string | null;
}>();

const open = ref<boolean>(false);
</script>

<template>
  <div>
    <Button variant="outline" size="sm" @click="open = true">
      {{ t('i18n.components.engine.export.export_button.export') }}
    </Button>

    <ExportDialog
      v-model:open="open"
      :object-type="props.objectType"
      :field-definitions="props.fieldDefinitions"
      :snapshot-provider="props.snapshotProvider"
      :segment-id="props.segmentId ?? null"
    />
  </div>
</template>
