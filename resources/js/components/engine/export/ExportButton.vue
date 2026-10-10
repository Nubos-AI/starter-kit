<script setup lang="ts">
import { ref } from 'vue';
import ExportDialog from '@/components/engine/export/ExportDialog.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { SnapshotProvider } from '@/composables/useRecordSelection';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

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
