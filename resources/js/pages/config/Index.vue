<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed } from 'vue';
import ConfigBundleController from '@/actions/App/Http/Controllers/ConfigBundle/ConfigBundleController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';
import type { ConfigIndexProps } from '@/types/configBundle';

const { t } = useI18n();

const props = defineProps<ConfigIndexProps>();

const page = usePage();

const form = useForm<{ file: File | null }>({ file: null });

const exportError = computed<string | undefined>(
    () => page.props.errors?.export,
);

const canUpload = computed<boolean>(
    () => props.can_import && form.file !== null && !form.processing,
);

function chooseArchive(file: File): void {
    form.file = file;
}

function upload(): void {
    if (!canUpload.value) {
        return;
    }

    form.post(ConfigBundleController.upload.url());
}
</script>

<template>
    <Head :title="t('i18n.pages.config.index.configuration')" />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="t('i18n.pages.config.index.configuration')"
            :description="
                t(
                    'i18n.pages.config.index.download_this_tenant_s_configuration_as_an_archive_or',
                )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{ t('i18n.pages.config.index.export') }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.config.index.the_archive_contains_one_yaml_file_per_artifact_object',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-2">
                <div>
                    <Button v-if="props.can_export" as-child>
                        <a
                            :href="ConfigBundleController.exportMethod.url()"
                            data-config-export
                        >
                            {{ t('i18n.pages.config.index.download_archive') }}
                        </a>
                    </Button>

                    <TooltipProvider v-else :delay-duration="0">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span tabindex="0" class="inline-flex">
                                    <Button disabled data-config-export>
                                        {{
                                            t(
                                                'i18n.pages.config.index.download_archive',
                                            )
                                        }}
                                    </Button>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent data-config-export-reason>
                                {{ props.export_reason }}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>

                <InputError :message="exportError" />
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('i18n.pages.config.index.import') }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.config.index.an_uploaded_archive_is_not_applied_immediately_a_review',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <Alert v-if="!props.can_import" data-config-import-reason>
                    <Info />
                    <AlertTitle>{{
                        t('i18n.pages.config.index.import_not_possible')
                    }}</AlertTitle>
                    <AlertDescription>
                        {{ props.import_reason }}
                    </AlertDescription>
                </Alert>

                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="config-import-file">{{
                        t('i18n.pages.config.index.archive')
                    }}</Label>
                    <FileDropzone
                        input-id="config-import-file"
                        accept=".zip,application/zip"
                        hint="ZIP-Archiv aus einem früheren Export"
                        :file-name="form.file?.name ?? null"
                        :disabled="!props.can_import"
                        @select="chooseArchive"
                    />
                    <InputError :message="form.errors.file" />
                </div>

                <div class="flex justify-end">
                    <Button
                        type="button"
                        :disabled="!canUpload"
                        data-config-import-submit
                        @click="upload"
                    >
                        {{ t('i18n.pages.config.index.upload') }}
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
