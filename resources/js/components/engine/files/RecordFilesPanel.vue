<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { useI18n } from '@/composables/useI18n';
import { useRecordFiles } from '@/composables/useRecordFiles';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{ recordId: string; readonly?: boolean }>(),
    { readonly: false },
);
const emit = defineEmits<{ changed: [] }>();
const {
    error,
    loading,
    uploading,
    progress,
    canUpload,
    accept,
    maxSizeKb,
    load,
    upload,
} = useRecordFiles(() => props.recordId);
const busy = computed(() => loading.value || uploading.value);
const hint = computed(() =>
    t('i18n.components.engine.files.record_files_panel.maximum_mb_per_file', {
        value1: (maxSizeKb.value / 1024).toLocaleString('de-DE'),
    }),
);

async function onUpload(file: File): Promise<void> {
    if (!props.readonly && (await upload(file))) {
        emit('changed');
    }
}
</script>

<template>
    <Card data-record-files-panel>
        <CardHeader>
            <CardTitle>{{
                t('i18n.components.engine.files.record_files_panel.files')
            }}</CardTitle>
            <CardDescription>{{
                t(
                    'i18n.components.engine.files.record_files_panel.upload_a_file_to_add_it_to_the_timeline',
                )
            }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
            <FileDropzone
                v-if="canUpload && !props.readonly"
                :input-id="`record-file-${props.recordId}`"
                :accept="accept"
                :hint="hint"
                :disabled="busy"
                @select="onUpload"
            />
            <div v-if="uploading" role="status" class="space-y-2 text-sm">
                <p>
                    {{
                        t(
                            'i18n.components.engine.files.record_files_panel.uploading_file',
                        )
                    }}{{ progress === undefined ? '…' : `: ${progress} %` }}
                </p>
                <progress
                    class="h-2 w-full"
                    :value="progress"
                    max="100"
                    :aria-label="
                        t(
                            'i18n.components.engine.files.record_files_panel.upload_progress',
                        )
                    "
                />
            </div>
            <div v-if="error" role="alert" class="text-sm text-destructive">
                {{ error }}
                <Button variant="link" :disabled="busy" @click="load()">{{
                    t('i18n.components.engine.files.record_files_panel.reload')
                }}</Button>
            </div>
            <p
                v-if="loading && !uploading"
                role="status"
                class="text-sm text-muted-foreground"
            >
                {{
                    t(
                        'i18n.components.engine.files.record_files_panel.preparing_upload',
                    )
                }}
            </p>
            <p v-if="props.readonly" class="text-sm text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.files.record_files_panel.files_on_a_deleted_record_cannot_be_changed',
                    )
                }}
            </p>
            <p
                v-else-if="!loading && !error && !canUpload"
                class="text-sm text-muted-foreground"
            >
                {{
                    t(
                        'i18n.components.engine.files.record_files_panel.you_do_not_have_permission_to_upload_files',
                    )
                }}
            </p>
        </CardContent>
    </Card>
</template>
