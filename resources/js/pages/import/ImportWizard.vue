<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import ColumnFormatOverride from '@/components/engine/import/ColumnFormatOverride.vue';
import DryRunSummary from '@/components/engine/import/DryRunSummary.vue';
import ImportProgress from '@/components/engine/import/ImportProgress.vue';
import MappingStep from '@/components/engine/import/MappingStep.vue';
import SheetPicker from '@/components/engine/import/SheetPicker.vue';
import UploadStep from '@/components/engine/import/UploadStep.vue';
import { Button } from '@/components/ui/button';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useImportWizard } from '@/composables/useImportWizard';
import type {
    ImportMappingPreset,
    ImportStep,
} from '@/composables/useImportWizard';
import type { JobExecutePayload } from '@/composables/useJobProgress';
import { usePermissions } from '@/composables/usePermissions';
import type { FieldDefinition } from '@/types/fields';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        objectType: string;
        fields: FieldDefinition[];
        presets?: ImportMappingPreset[];
    }>(),
    { presets: () => [] },
);

const { canForObjectType } = usePermissions();

usePageBreadcrumbs(() => [
    { title: t('i18n.pages.import.import_wizard.import') },
]);

const wizard = useImportWizard(props.objectType, props.fields);

wizard.presets.value = props.presets;

const stepLabels: Record<ImportStep, string> = {
    upload: t('i18n.pages.import.import_wizard.file'),
    sheet: t('i18n.pages.import.import_wizard.worksheet'),
    mapping: t('i18n.pages.import.import_wizard.assignment'),
    format: t('i18n.pages.import.import_wizard.formats'),
    dryRun: t('i18n.pages.import.import_wizard.preview'),
};

const orderedSteps = computed<ImportStep[]>(() => {
    const needsSheet =
        wizard.uploadResult.value?.format === 'xlsx' &&
        (wizard.uploadResult.value?.sheets.length ?? 0) > 1;

    return [
        'upload',
        ...(needsSheet ? (['sheet'] as ImportStep[]) : []),
        'mapping',
        'format',
        'dryRun',
    ];
});

const headers = computed<string[]>(
    () => wizard.uploadResult.value?.headerSuggestion ?? [],
);

const currentIndex = computed<number>(() =>
    orderedSteps.value.indexOf(wizard.currentStep.value),
);

const canGoBack = computed<boolean>(() => currentIndex.value > 0);

const canGoNext = computed<boolean>(
    () =>
        wizard.uploadResult.value !== null &&
        currentIndex.value < orderedSteps.value.length - 1,
);

function goNext(): void {
    if (canGoNext.value) {
        wizard.currentStep.value = orderedSteps.value[currentIndex.value + 1];
    }
}

function goBack(): void {
    if (canGoBack.value) {
        wizard.currentStep.value = orderedSteps.value[currentIndex.value - 1];
    }
}

const started = ref<boolean>(false);
const progressRef = ref<InstanceType<typeof ImportProgress> | null>(null);

function buildPayload(): JobExecutePayload | null {
    const upload = wizard.uploadResult.value;

    if (upload === null) {
        return null;
    }

    return {
        path: upload.path,
        format: upload.format,
        sheet: wizard.sheet.value,
        mapping: wizard.mapping.value,
        duplicate_mode: wizard.duplicateMode.value,
        missing_option_mode: wizard.missingOptionMode.value,
    };
}

async function startImport(): Promise<void> {
    const payload = buildPayload();

    if (payload === null) {
        return;
    }

    started.value = true;
    await nextTick();
    progressRef.value?.start(payload, { objectType: props.objectType });
}
</script>

<template>
    <Head :title="t('i18n.pages.import.import_wizard.import_records')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-3">
        <div class="flex flex-col gap-1">
            <h1 class="text-lg font-semibold">
                {{ t('i18n.pages.import.import_wizard.import_records') }}
            </h1>
            <ol class="flex flex-wrap gap-2 text-sm text-muted-foreground">
                <li
                    v-for="(step, index) in orderedSteps"
                    :key="step"
                    :class="{
                        'font-semibold text-foreground':
                            step === wizard.currentStep.value,
                    }"
                >
                    {{ index + 1 }}. {{ stepLabels[step] }}
                </li>
            </ol>
        </div>

        <UploadStep
            v-if="wizard.currentStep.value === 'upload'"
            :loading="wizard.loading.value"
            :error="wizard.error.value"
            :upload-result="wizard.uploadResult.value"
            :format-override="wizard.formatOverride.value"
            @upload="wizard.upload"
            @override="wizard.setFormatOverride"
        />

        <SheetPicker
            v-else-if="wizard.currentStep.value === 'sheet'"
            :sheets="wizard.uploadResult.value?.sheets ?? []"
            :model-value="wizard.sheet.value"
            @update:model-value="wizard.selectSheet"
        />

        <MappingStep
            v-else-if="wizard.currentStep.value === 'mapping'"
            :headers="headers"
            :fields="props.fields"
            :mapping="wizard.mapping.value"
            :duplicate-mode="wizard.duplicateMode.value"
            :missing-option-mode="wizard.missingOptionMode.value"
            :presets="wizard.presets.value"
            @update:mapping="wizard.setMapping"
            @update:duplicate-mode="wizard.duplicateMode.value = $event"
            @update:missing-option-mode="
                wizard.missingOptionMode.value = $event
            "
            @auto-match="wizard.autoMatch"
            @save-preset="wizard.savePreset"
            @load-preset="wizard.loadPreset"
        />

        <ColumnFormatOverride
            v-else-if="wizard.currentStep.value === 'format'"
            :headers="headers"
            :columns="wizard.mapping.value.columns"
            :formats="wizard.mapping.value.formats"
            @update:formats="wizard.setMapping({ formats: $event })"
        />

        <DryRunSummary
            v-else-if="wizard.currentStep.value === 'dryRun'"
            :dry-run-result="wizard.dryRunResult.value"
            :loading="wizard.loading.value"
            :error="wizard.error.value"
            :can-start-import="wizard.canStartImport.value"
            :can-import="canForObjectType(props.objectType, 'import')"
            @run="wizard.runDryRun"
            @start="startImport"
        />

        <ImportProgress v-if="started" ref="progressRef" />

        <div class="flex justify-between gap-2">
            <Button variant="outline" :disabled="!canGoBack" @click="goBack">
                {{ t('i18n.pages.import.import_wizard.back') }}
            </Button>
            <Button :disabled="!canGoNext" @click="goNext">{{
                t('i18n.pages.import.import_wizard.continue')
            }}</Button>
        </div>
    </div>
</template>
