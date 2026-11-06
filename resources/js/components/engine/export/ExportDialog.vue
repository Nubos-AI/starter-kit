<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import FieldSelector from '@/components/engine/export/FieldSelector.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { exportableFieldOptions, useExport } from '@/composables/useExport';
import type {
    ExportFieldOption,
    ExportFormat,
    ExportScope,
    ExportScopeMode,
    ExportStartPayload,
} from '@/composables/useExport';
import { useI18n } from '@/composables/useI18n';
import type { SnapshotProvider } from '@/composables/useRecordSelection';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

const { t } = useI18n();

const props = defineProps<{
    open: boolean;
    objectType: RecordObjectType;
    fieldDefinitions: FieldDefinition[];
    snapshotProvider: SnapshotProvider;
    segmentId?: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const exportJob = useExport();

const formatOptions: Array<{ value: ExportFormat; label: string }> = [
    {
        value: 'csv',
        label: t('i18n.components.engine.export.export_dialog.csv'),
    },
    {
        value: 'xlsx',
        label: t('i18n.components.engine.export.export_dialog.excel_xlsx'),
    },
    {
        value: 'json',
        label: t('i18n.components.engine.export.export_dialog.json'),
    },
];

const format = ref<ExportFormat>('csv');
const scopeMode = ref<ExportScopeMode>('view');

const fieldOptions = computed<ExportFieldOption[]>(() =>
    exportableFieldOptions(props.fieldDefinitions),
);

const selectedFields = ref<string[]>(
    exportableFieldOptions(props.fieldDefinitions).map((option) => option.key),
);
const presetName = ref<string>('');
const selectedPresetId = ref<string>('');

const presetOptions = computed<Array<{ value: string; label: string }>>(() =>
    exportJob.presets.value.map((preset) => ({
        value: preset.id,
        label: preset.name,
    })),
);

const canSavePreset = computed<boolean>(
    () => presetName.value.trim().length > 0 && selectedFields.value.length > 0,
);

const hasSegment = computed<boolean>(
    () => typeof props.segmentId === 'string' && props.segmentId.length > 0,
);

const scopeOptions = computed<Array<{ value: ExportScopeMode; label: string }>>(
    () => {
        const options: Array<{ value: ExportScopeMode; label: string }> = [
            {
                value: 'view',
                label: t(
                    'i18n.components.engine.export.export_dialog.current_view_filters_sorting',
                ),
            },
        ];

        if (hasSegment.value) {
            options.push({
                value: 'segment',
                label: t(
                    'i18n.components.engine.export.export_dialog.active_segment',
                ),
            });
        }

        options.push({
            value: 'whole-type',
            label: t(
                'i18n.components.engine.export.export_dialog.entire_object_type',
            ),
        });

        return options;
    },
);

const progressValue = computed<number>(() =>
    Math.max(0, Math.min(100, Math.round(exportJob.progress.value))),
);

const isRunning = computed<boolean>(() => exportJob.active.value);

const canExport = computed<boolean>(
    () => !isRunning.value && selectedFields.value.length > 0,
);

function buildScope(): ExportScope {
    if (scopeMode.value === 'segment' && hasSegment.value) {
        return { mode: 'segment', segmentId: props.segmentId as string };
    }

    if (scopeMode.value === 'whole-type') {
        return { mode: 'whole-type' };
    }

    const snapshot = props.snapshotProvider();

    return {
        mode: 'view',
        filterModel: snapshot.filterModel,
        sortModel: snapshot.sortModel,
        search: snapshot.search,
    };
}

function onExport(): void {
    const payload: ExportStartPayload = {
        format: format.value,
        scope: buildScope(),
        fields: selectedFields.value,
    };

    exportJob.start(payload, { objectType: props.objectType.slug });
}

function applyPreset(presetId: string): void {
    selectedPresetId.value = presetId;

    const preset = exportJob.presets.value.find(
        (candidate) => candidate.id === presetId,
    );

    if (preset === undefined) {
        return;
    }

    const available = new Set(fieldOptions.value.map((option) => option.key));

    selectedFields.value = preset.fields.filter((field) =>
        available.has(field),
    );
}

async function onSavePreset(): Promise<void> {
    if (!canSavePreset.value) {
        return;
    }

    const preset = await exportJob.savePreset(
        props.objectType.slug,
        presetName.value.trim(),
        selectedFields.value,
    );

    if (preset !== null) {
        presetName.value = '';
        selectedPresetId.value = preset.id;
    }
}

function onOpenChange(value: boolean): void {
    emit('update:open', value);
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        format.value = 'csv';
        scopeMode.value = 'view';
        selectedFields.value = fieldOptions.value.map((option) => option.key);
        presetName.value = '';
        selectedPresetId.value = '';
        exportJob.reset();
        void exportJob.loadPresets(props.objectType.slug);
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >{{ objectType.name }}
                    {{
                        t('i18n.components.engine.export.export_dialog.export')
                    }}</DialogTitle
                >
                <DialogDescription>
                    {{
                        t(
                            'i18n.components.engine.export.export_dialog.choose_the_format_scope_and_fields_the_export_runs',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-2">
                    <Label for="export-format">{{
                        t('i18n.components.engine.export.export_dialog.format')
                    }}</Label>
                    <Select
                        :model-value="format"
                        @update:model-value="format = $event as ExportFormat"
                    >
                        <SelectTrigger id="export-format">
                            <SelectValue
                                :placeholder="
                                    t(
                                        'i18n.components.engine.export.export_dialog.select_format',
                                    )
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in formatOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="export-scope">{{
                        t('i18n.components.engine.export.export_dialog.scope')
                    }}</Label>
                    <Select
                        :model-value="scopeMode"
                        @update:model-value="
                            scopeMode = $event as ExportScopeMode
                        "
                    >
                        <SelectTrigger id="export-scope">
                            <SelectValue
                                :placeholder="
                                    t(
                                        'i18n.components.engine.export.export_dialog.select_scope',
                                    )
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in scopeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <FieldSelector
                    v-model="selectedFields"
                    :fields="fieldOptions"
                />

                <div class="flex flex-col gap-3 border-t pt-4">
                    <div
                        v-if="presetOptions.length > 0"
                        class="flex flex-col gap-2"
                    >
                        <Label for="export-preset">{{
                            t(
                                'i18n.components.engine.export.export_dialog.load_preset',
                            )
                        }}</Label>
                        <Select
                            :model-value="selectedPresetId"
                            @update:model-value="applyPreset($event as string)"
                        >
                            <SelectTrigger id="export-preset">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.components.engine.export.export_dialog.select_preset',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in presetOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="export-preset-name">
                            {{
                                t(
                                    'i18n.components.engine.export.export_dialog.save_current_field_selection_as_a_preset',
                                )
                            }}
                        </Label>
                        <div class="flex items-center gap-2">
                            <Input
                                id="export-preset-name"
                                v-model="presetName"
                                type="text"
                                :placeholder="
                                    t(
                                        'i18n.components.engine.export.export_dialog.preset_name',
                                    )
                                "
                                class="flex-1"
                            />
                            <Button
                                variant="outline"
                                :disabled="!canSavePreset"
                                @click="onSavePreset"
                            >
                                {{
                                    t(
                                        'i18n.components.engine.export.export_dialog.save_preset',
                                    )
                                }}
                            </Button>
                        </div>
                    </div>
                </div>

                <div
                    v-if="exportJob.status.value === 'polling'"
                    class="flex flex-col gap-2"
                    role="status"
                    aria-live="polite"
                    :aria-busy="true"
                >
                    <div
                        class="h-2 w-full overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="progressValue"
                        :aria-valuemin="0"
                        :aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-primary transition-all"
                            :style="{ width: `${progressValue}%` }"
                        />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'i18n.components.engine.export.export_dialog.exporting',
                            )
                        }}
                        {{ progressValue }}%
                    </p>
                </div>

                <Alert
                    v-else-if="exportJob.status.value === 'failed'"
                    variant="destructive"
                    role="alert"
                >
                    <AlertTitle>{{
                        t(
                            'i18n.components.engine.export.export_dialog.export_failed',
                        )
                    }}</AlertTitle>
                    <AlertDescription>
                        {{
                            t(
                                'i18n.components.engine.export.export_dialog.the_export_could_not_be_completed_please_try_again',
                            )
                        }}
                    </AlertDescription>
                </Alert>

                <Alert
                    v-else-if="
                        exportJob.status.value === 'finished' &&
                        exportJob.downloadUrl.value !== null
                    "
                    role="status"
                >
                    <AlertTitle>{{
                        t(
                            'i18n.components.engine.export.export_dialog.export_complete',
                        )
                    }}</AlertTitle>
                    <AlertDescription class="flex flex-col gap-3">
                        {{
                            t(
                                'i18n.components.engine.export.export_dialog.the_export_file_is_ready_to_download',
                            )
                        }}
                        <Button
                            as="a"
                            variant="outline"
                            size="sm"
                            :href="exportJob.downloadUrl.value"
                            class="self-start"
                        >
                            {{
                                t(
                                    'i18n.components.engine.export.export_dialog.download_file',
                                )
                            }}
                        </Button>
                    </AlertDescription>
                </Alert>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="onOpenChange(false)">
                    {{ t('i18n.components.engine.export.export_dialog.close') }}
                </Button>
                <Button :disabled="!canExport" @click="onExport">
                    {{
                        t(
                            'i18n.components.engine.export.export_dialog.start_export',
                        )
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
