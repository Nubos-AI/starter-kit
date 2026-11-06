<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import { destroy } from '@/actions/App/Http/Controllers/Engine/RecordFilesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import RecordFileRow from '@/components/engine/files/RecordFileRow.vue';
import { useI18n } from '@/composables/useI18n';
import type { TimelineEntry } from '@/types/timeline';
import { readAttachmentPayload } from '@/types/timeline';

const { t } = useI18n();

const props = defineProps<{ entry: TimelineEntry; recordId: string }>();
const emit = defineEmits<{ changed: [] }>();
const removal = useHttp({});
const confirming = ref(false);
const deleted = ref(false);
const error = ref<string | null>(null);

async function remove(): Promise<void> {
    const file = props.entry.file;

    if (!file?.canDelete || removal.processing) {
        return;
    }

    error.value = null;

    try {
        await removal.delete(
            destroy.url({ record: props.recordId, attachment: file.id }),
        );
        confirming.value = false;
        deleted.value = true;
        emit('changed');
    } catch {
        error.value = t(
            'i18n.components.engine.files.timeline_file_entry.the_file_could_not_be_deleted_please_try_again',
        );
    }
}
</script>

<template>
    <div data-timeline-title data-timeline-file>
        <RecordFileRow
            v-if="entry.file && !deleted"
            :file="entry.file"
            :record-id="recordId"
            :busy="removal.processing"
            @remove="confirming = true"
        />
        <p v-else class="text-sm">
            <span class="font-medium">{{
                readAttachmentPayload(entry.payload).fileName
            }}</span>
            <span class="ml-2 text-xs text-muted-foreground">{{
                t(
                    'i18n.components.engine.files.timeline_file_entry.file_no_longer_available',
                )
            }}</span>
        </p>
        <ConfirmDialog
            :open="confirming"
            :title="
                t(
                    'i18n.components.engine.files.timeline_file_entry.delete_file',
                )
            "
            :description="
                t(
                    'i18n.components.engine.files.timeline_file_entry.will_be_permanently_removed_from_storage',
                    { value1: entry.file?.fileName ?? '' },
                )
            "
            :confirm-label="
                t(
                    'i18n.components.engine.files.timeline_file_entry.delete_file_2',
                )
            "
            variant="destructive"
            :pending="removal.processing"
            @confirm="remove"
            @update:open="
                (open) => {
                    if (!removal.processing) confirming = open;
                }
            "
        >
            <p v-if="error" role="alert" class="text-sm text-destructive">
                {{ error }}
            </p>
        </ConfirmDialog>
    </div>
</template>
