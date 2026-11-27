<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConfigBundleController from '@/actions/App/Http/Controllers/ConfigBundle/ConfigBundleController';
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { FileDropzone } from '@/shared/ui/primitives/file-dropzone';
import ReadOnlyNotice from '@/shared/ui/ReadOnlyNotice.vue';
import ReasonedButton from '@/shared/ui/ReasonedButton.vue';
import type { ConfigIndexProps } from '../../types/configBundle';

const { t } = useI18n();

const props = defineProps<ConfigIndexProps>();

const page = usePage();

const form = useForm<{ file: File | null }>({ file: null });

const exportError = computed<string | undefined>(() => page.props.errors?.export);

const canUpload = computed<boolean>(() => props.can_import && form.file !== null && !form.processing);

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
  <PageShell
    :title="t('i18n.pages.config.index.configuration')"
    :description="t('i18n.pages.config.index.download_this_tenant_s_configuration_as_an_archive_or')"
  >
    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.config.index.export') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.config.index.the_archive_contains_one_yaml_file_per_artifact_object') }}
        </CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-2">
        <div>
          <Button v-if="props.can_export" as-child>
            <a :href="ConfigBundleController.exportMethod.url()" data-config-export>
              {{ t('i18n.pages.config.index.download_archive') }}
            </a>
          </Button>

          <ReasonedButton v-else :reason="props.export_reason ?? ''" data-config-export>
            {{ t('i18n.pages.config.index.download_archive') }}
          </ReasonedButton>
        </div>

        <InputError :message="exportError" />
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.config.index.import') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.config.index.an_uploaded_archive_is_not_applied_immediately_a_review') }}
        </CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-4">
        <ReadOnlyNotice
          v-if="!props.can_import"
          :title="t('i18n.pages.config.index.import_not_possible')"
          :reason="props.import_reason ?? ''"
          data-config-import-reason
        />

        <FormField
          for="config-import-file"
          :label="t('i18n.pages.config.index.archive')"
          :error="form.errors.file"
          class="sm:max-w-sm"
        >
          <FileDropzone
            input-id="config-import-file"
            accept=".zip,application/zip"
            :hint="t('i18n.pages.config.index.zip_archive_from_an_earlier_export')"
            :file-name="form.file?.name ?? null"
            :disabled="!props.can_import"
            @select="chooseArchive"
          />
        </FormField>

        <div class="flex justify-end">
          <Button type="button" :disabled="!canUpload" data-config-import-submit @click="upload">
            {{ t('i18n.pages.config.index.upload') }}
          </Button>
        </div>
      </CardContent>
    </Card>
  </PageShell>
</template>
