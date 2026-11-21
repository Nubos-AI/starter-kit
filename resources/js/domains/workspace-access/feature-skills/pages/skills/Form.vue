<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SkillsController from '@/actions/App/Http/Controllers/Skills/SkillsController';
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

const { t } = useI18n();

interface SkillPayload {
  id: string;
  name: string;
  userIds: string[];
}

const props = withDefaults(
  defineProps<{
    mode: 'create' | 'edit';
    skill: SkillPayload | null;
    userOptions?: SelectOption[];
  }>(),
  { userOptions: () => [] },
);

const heading = computed<string>(() =>
  props.mode === 'create'
    ? t('i18n.pages.skills.form.create_skill')
    : (props.skill?.name ?? t('i18n.pages.skills.form.skill')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const formAction = computed(() =>
  props.mode === 'create'
    ? SkillsController.store.form()
    : SkillsController.update.form({ skill: props.skill?.id ?? '' }),
);

const name = ref<string>(props.skill?.name ?? '');
const userIds = ref<string[]>([...(props.skill?.userIds ?? [])]);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    name: name.value,
    userIds: [...userIds.value].sort(),
  }),
  backHref: SkillsController.index.url(),
});

function withSelections(data: Record<string, FormDataConvertible>): Record<string, FormDataConvertible> {
  if (props.mode === 'create') {
    return { ...data };
  }

  return { ...data, user_ids: userIds.value };
}
</script>

<template>
  <PageShell
    :title="heading"
    :description="
      mode === 'create'
        ? t('i18n.pages.skills.form.create_a_skill_that_you_can_then_assign_to')
        : t('i18n.pages.skills.form.rename_this_skill_or_change_who_has_it')
    "
    :head-title="
      mode === 'create'
        ? t('i18n.pages.skills.form.create_skill')
        : t('i18n.pages.skills.form.edit', { value1: skill?.name })
    "
  >
    <Form
      v-bind="formAction"
      :transform="withSelections"
      :on-success="markSaved"
      class="flex flex-col gap-6"
      v-slot="{ errors, processing }"
    >
      <Card>
        <CardHeader>
          <CardTitle>{{ t('i18n.pages.skills.form.details') }}</CardTitle>
          <CardDescription>
            {{ t('i18n.pages.skills.form.the_name_appears_wherever_a_skill_is_selected') }}
          </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
          <FormField
            for="skill-name"
            :label="t('i18n.pages.skills.form.name')"
            :error="errors.name"
            class="sm:max-w-sm"
          >
            <Input
              id="skill-name"
              v-model="name"
              name="name"
              :placeholder="t('i18n.pages.skills.form.e_g_accounting_legal_purchasing')"
              required
            />
          </FormField>

          <FormActions v-if="mode === 'create'" :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </CardContent>
      </Card>

      <Card v-if="mode === 'edit'">
        <CardHeader>
          <CardTitle>{{ t('i18n.pages.skills.form.assigned_users') }}</CardTitle>
          <CardDescription>
            {{ t('i18n.pages.skills.form.users_listed_here_have_this_skill_and_are_eligible') }}
          </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
          <FormField
            for="skill-users"
            :label="t('i18n.pages.skills.form.users')"
            :error="errors.user_ids"
            class="sm:max-w-sm"
          >
            <MultiSelect
              id="skill-users"
              v-model="userIds"
              :options="props.userOptions"
              :placeholder="t('i18n.pages.skills.form.select_users')"
              :empty-label="t('i18n.pages.skills.form.there_are_no_users')"
              :aria-label="t('i18n.pages.skills.form.assigned_users')"
            />
          </FormField>

          <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </CardContent>
      </Card>
    </Form>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
