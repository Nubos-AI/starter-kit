<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApiTokensController from '@/actions/App/Http/Controllers/Api/ApiTokensController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { SelectOption } from '@/shared/types/ui';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Input } from '@/shared/ui/primitives/input';
import { MultiSelect } from '@/shared/ui/primitives/multi-select';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import { accessLevelOptions } from '../../utils/apiTokenAccess';
import type { ObjectTypeAccess } from '../../utils/apiTokenAccess';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    objectTypeOptions?: SelectOption[];
  }>(),
  {
    objectTypeOptions: () => [],
  },
);

usePageBreadcrumbs(() => [{ title: t('i18n.pages.api_tokens.form.new_api_token') }]);

const name = ref<string>('');
const selectedObjectTypes = ref<string[]>([]);
const levelsByObjectType = ref<Record<string, string[]>>({});
const expiresAt = ref<string>('');

const accessRows = computed<SelectOption[]>(() =>
  props.objectTypeOptions.filter((option) => selectedObjectTypes.value.includes(option.value)),
);

const objectTypeAccess = computed<ObjectTypeAccess[]>(() =>
  accessRows.value.map((option) => ({
    objectType: option.value,
    levels: levelsByObjectType.value[option.value] ?? [],
  })),
);

watch(selectedObjectTypes, (selected) => {
  const next: Record<string, string[]> = {};

  selected.forEach((slug) => {
    next[slug] = levelsByObjectType.value[slug] ?? ['read'];
  });

  levelsByObjectType.value = next;
});

function levelsFor(slug: string): string[] {
  return levelsByObjectType.value[slug] ?? [];
}

function setLevels(slug: string, levels: string[]): void {
  levelsByObjectType.value = { ...levelsByObjectType.value, [slug]: levels };
}

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    name: name.value,
    objectTypeAccess: objectTypeAccess.value,
    expiresAt: expiresAt.value,
  }),
  backHref: ApiTokensController.index.url(),
});

function accessError(errors: Record<string, string>): string | undefined {
  const key = Object.keys(errors).find((candidate) => candidate.startsWith('objectTypeAccess'));

  return key === undefined ? undefined : errors[key];
}

function withSelections(data: Record<string, FormDataConvertible>): Record<string, FormDataConvertible> {
  return {
    ...data,
    objectTypeAccess: objectTypeAccess.value,
    expiresAt: expiresAt.value === '' ? null : expiresAt.value,
  };
}
</script>

<template>
  <PageShell
    :title="t('i18n.pages.api_tokens.form.new_api_token')"
    :description="t('i18n.pages.api_tokens.form.the_secret_is_shown_only_once_after_creation_store')"
  >
    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.api_tokens.form.details') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.api_tokens.form.this_token_does_not_belong_to_a_person_it') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form
          v-bind="ApiTokensController.store.form()"
          :transform="withSelections"
          :on-success="markSaved"
          class="flex flex-col gap-4"
          v-slot="{ errors, processing }"
        >
          <FormField
            for="api-token-name"
            :label="t('i18n.pages.api_tokens.form.name')"
            :error="errors.name"
            class="sm:max-w-sm"
          >
            <Input
              id="api-token-name"
              v-model="name"
              name="name"
              type="text"
              required
              :placeholder="t('i18n.pages.api_tokens.form.e_g_ci_integration')"
              data-api-token-name
            />
          </FormField>

          <FormField
            for="api-token-object-types"
            :label="t('i18n.pages.api_tokens.form.object_types')"
            :error="accessError(errors)"
            :hint="t('i18n.pages.api_tokens.form.only_the_selected_object_types_are_accessible_through_this')"
            class="sm:max-w-sm"
          >
            <MultiSelect
              id="api-token-object-types"
              v-model="selectedObjectTypes"
              :options="props.objectTypeOptions"
              :placeholder="t('i18n.pages.api_tokens.form.select_object_types')"
              :empty-label="t('i18n.pages.api_tokens.form.no_object_types_available')"
              :aria-label="t('i18n.pages.api_tokens.form.object_types')"
            />
          </FormField>

          <div v-if="accessRows.length > 0" class="grid gap-3" data-api-token-access-matrix>
            <FormField
              v-for="row in accessRows"
              :key="row.value"
              :for="`api-token-levels-${row.value}`"
              :label="row.label"
              class="items-center sm:grid-cols-2"
            >
              <MultiSelect
                :id="`api-token-levels-${row.value}`"
                :model-value="levelsFor(row.value)"
                :options="accessLevelOptions"
                :placeholder="t('i18n.pages.api_tokens.form.select_access')"
                :aria-label="
                  t('i18n.pages.api_tokens.form.access_to', {
                    value1: row.label,
                  })
                "
                @update:model-value="setLevels(row.value, $event)"
              />
            </FormField>
          </div>

          <FormField
            for="api-token-expires"
            :label="t('i18n.pages.api_tokens.form.expires')"
            :error="errors.expiresAt"
            :hint="t('i18n.pages.api_tokens.form.optional_without_a_date_the_token_does_not_expire')"
            class="sm:max-w-sm"
          >
            <Input
              id="api-token-expires"
              v-model="expiresAt"
              name="expiresAt"
              type="datetime-local"
              data-api-token-expires
            />
          </FormField>

          <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </Form>
      </CardContent>
    </Card>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
