<script setup lang="ts">
import { ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import type {
    ImportFormatOverride,
    UploadResult,
} from '@/composables/useImportWizard';

const { t } = useI18n();

defineProps<{
    loading: boolean;
    error: string | null;
    uploadResult: UploadResult | null;
    formatOverride: ImportFormatOverride;
}>();

const emit = defineEmits<{
    upload: [file: File];
    override: [patch: ImportFormatOverride];
}>();

const selectedFileName = ref<string | null>(null);

function onFileSelected(file: File): void {
    selectedFileName.value = file.name;

    emit('upload', file);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-2">
            <Label for="import-file">{{
                t(
                    'i18n.components.engine.import.upload_step.select_file_csv_or_xlsx',
                )
            }}</Label>
            <FileDropzone
                input-id="import-file"
                accept=".csv,.xlsx,text/csv"
                hint="CSV oder XLSX"
                :file-name="selectedFileName"
                :disabled="loading"
                @select="onFileSelected"
            />
        </div>

        <div
            v-if="loading"
            class="flex flex-col gap-2"
            role="status"
            aria-live="polite"
            aria-busy="true"
        >
            <span class="sr-only">{{
                t('i18n.components.engine.import.upload_step.uploading_file')
            }}</span>
            <Skeleton class="h-6 w-40" />
            <Skeleton class="h-6 w-64" />
        </div>

        <Alert v-else-if="error" variant="destructive" role="alert">
            <AlertTitle>{{
                t('i18n.components.engine.import.upload_step.upload_failed')
            }}</AlertTitle>
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>

        <div v-else-if="uploadResult" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <Badge variant="secondary">{{
                    uploadResult.format.toUpperCase()
                }}</Badge>
                <Badge v-if="uploadResult.encoding" variant="outline">
                    {{ uploadResult.encoding }}
                </Badge>
                <Badge v-if="uploadResult.delimiter" variant="outline">
                    {{ t('i18n.components.engine.import.upload_step.delimiter')
                    }}{{ uploadResult.delimiter }}"
                </Badge>
            </div>

            <div
                v-if="uploadResult.format === 'csv'"
                class="flex flex-wrap items-end gap-4"
            >
                <div class="flex flex-col gap-1">
                    <Label for="import-encoding">{{
                        t(
                            'i18n.components.engine.import.upload_step.override_encoding',
                        )
                    }}</Label>
                    <Input
                        id="import-encoding"
                        :model-value="
                            formatOverride.encoding ??
                            uploadResult.encoding ??
                            ''
                        "
                        :placeholder="
                            t('i18n.components.engine.import.upload_step.utf_8')
                        "
                        class="w-40"
                        @update:model-value="
                            emit('override', { encoding: String($event) })
                        "
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <Label for="import-delimiter">{{
                        t(
                            'i18n.components.engine.import.upload_step.override_delimiter',
                        )
                    }}</Label>
                    <Input
                        id="import-delimiter"
                        :model-value="
                            formatOverride.delimiter ??
                            uploadResult.delimiter ??
                            ''
                        "
                        :placeholder="
                            t(
                                'i18n.components.engine.import.upload_step.example',
                            )
                        "
                        class="w-24"
                        @update:model-value="
                            emit('override', { delimiter: String($event) })
                        "
                    />
                </div>
            </div>
        </div>

        <p v-else class="text-sm text-muted-foreground">
            {{
                t(
                    'i18n.components.engine.import.upload_step.no_file_uploaded_yet',
                )
            }}
        </p>
    </div>
</template>
