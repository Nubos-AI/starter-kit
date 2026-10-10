<script setup lang="ts">
import { Download, Eye, Trash2 } from '@lucide/vue';
import { show } from '@/actions/App/Http/Controllers/Engine/RecordFilesController';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { RecordFile } from '@/types/attachments';

const { t } = useI18n();

defineProps<{
    file: RecordFile;
    recordId: string;
    busy: boolean;
}>();
const emit = defineEmits<{ remove: [file: RecordFile] }>();

function sizeLabel(size: number): string {
    if (size < 1024) {
        return t('i18n.components.engine.files.record_file_row.b', {
            value1: size,
        });
    }

    if (size < 1024 * 1024) {
        return t('i18n.components.engine.files.record_file_row.kb', {
            value1: Math.round(size / 1024),
        });
    }

    return t('i18n.components.engine.files.record_file_row.mb', {
        value1: (size / 1024 / 1024).toLocaleString('de-DE', {
            maximumFractionDigits: 1,
        }),
    });
}
</script>

<template>
    <div class="flex items-center gap-2" data-record-file>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium" :title="file.fileName">
                {{ file.fileName }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ sizeLabel(file.size) }}
            </p>
        </div>
        <Button
            v-if="
                [
                    'application/pdf',
                    'image/png',
                    'image/jpeg',
                    'image/gif',
                    'image/webp',
                    'text/plain',
                ].includes(file.mimeType)
            "
            variant="ghost"
            size="icon"
            class="size-11 shrink-0"
            as-child
        >
            <a
                :href="
                    show.url(
                        { record: recordId, attachment: file.id },
                        { query: { preview: true } },
                    )
                "
                target="_blank"
                rel="noopener noreferrer"
                :aria-label="
                    t('i18n.components.engine.files.record_file_row.view', {
                        value1: file.fileName,
                    })
                "
                :title="
                    t('i18n.components.engine.files.record_file_row.preview')
                "
            >
                <Eye class="size-4" />
            </a>
        </Button>
        <Button variant="ghost" size="icon" class="size-11 shrink-0" as-child>
            <a
                :href="show.url({ record: recordId, attachment: file.id })"
                :aria-label="
                    t(
                        'i18n.components.engine.files.record_file_row.download_2',
                        { value1: file.fileName },
                    )
                "
                :title="
                    t('i18n.components.engine.files.record_file_row.download')
                "
            >
                <Download class="size-4" />
            </a>
        </Button>
        <Button
            v-if="file.canDelete"
            variant="ghost"
            size="icon"
            class="size-11 shrink-0"
            :disabled="busy"
            :aria-label="
                t('i18n.components.engine.files.record_file_row.delete_2', {
                    value1: file.fileName,
                })
            "
            :title="t('i18n.components.engine.files.record_file_row.delete')"
            @click="emit('remove', file)"
        >
            <Trash2 class="size-4" />
        </Button>
    </div>
</template>
