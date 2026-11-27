<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfigBundleController from '@/actions/App/Http/Controllers/ConfigBundle/ConfigBundleController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import AlertError from '@/shared/ui/AlertError.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Input } from '@/shared/ui/primitives/input';
import ReadOnlyNotice from '@/shared/ui/ReadOnlyNotice.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import type { ConfigPlaceholdersProps } from '../../types/configBundle';

const { t } = useI18n();

const props = defineProps<ConfigPlaceholdersProps>();

usePageBreadcrumbs(() => [{ title: t('i18n.pages.config.placeholders.webhook_destinations') }]);

const targets = ref<string[]>(props.requirements.map((requirement) => requirement.current_value));

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => [...targets.value],
  backHref: ConfigBundleController.index.url(),
});
</script>

<template>
  <PageShell
    :title="t('i18n.pages.config.placeholders.webhook_destinations')"
    :description="t('i18n.pages.config.placeholders.enter_the_destinations_for_webhooks_from_the_archive_in')"
  >
    <ReadOnlyNotice v-if="!props.can_save" :reason="props.save_reason ?? ''" data-placeholder-locked />

    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.config.placeholders.webhook_destinations_2') }}</CardTitle>
        <CardDescription data-placeholder-explanation>
          {{
            t(
              'i18n.pages.config.placeholders.the_archive_deliberately_excludes_webhook_destinations_because_each_tenant',
            )
          }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form
          v-bind="
            ConfigBundleController.storePlaceholders.form({
              run: props.run.id,
            })
          "
          :on-success="markSaved"
          class="flex flex-col gap-4"
          v-slot="{ errors, processing }"
        >
          <AlertError v-if="errors.targets" data-placeholder-error :message="errors.targets" />

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField
              v-for="(requirement, index) in props.requirements"
              :key="requirement.artifact_key"
              data-placeholder-field
              :for="`placeholder-target-${index}`"
              :label="requirement.label"
              :error="errors[`targets.${index}.target_url`] ?? errors[`targets.${index}.key`]"
            >
              <input type="hidden" :name="`targets[${index}][key]`" :value="requirement.artifact_key" />
              <Input
                :id="`placeholder-target-${index}`"
                v-model="targets[index]"
                :name="`targets[${index}][target_url]`"
                inputmode="url"
                autocomplete="off"
                :placeholder="t('i18n.pages.config.placeholders.https')"
                :disabled="!props.can_save"
              />
            </FormField>
          </div>

          <FormActions :dirty="isDirty && props.can_save" :processing="processing" @cancel="requestLeave" />
        </Form>
      </CardContent>
    </Card>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
