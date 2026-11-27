<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UserInvitationsController from '@/actions/App/Http/Controllers/Users/UserInvitationsController';
import UsersController from '@/actions/App/Http/Controllers/Users/UsersController';
import type { AssignableRole } from '@/domains/workspace-access/data';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import type { MultiSelectOption } from '@/shared/ui/primitives/multi-select';
import { MultiSelect } from '@/shared/ui/primitives/multi-select';
import { Textarea } from '@/shared/ui/primitives/textarea';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

const { t } = useI18n();

const props = defineProps<{
  management?: { storeUrl: string; indexUrl: string };
  roles: AssignableRole[];
  teams: MultiSelectOption[];
}>();

usePageBreadcrumbs(() => [{ title: t('i18n.pages.users.invite.invite_users') }]);

const emails = ref<string>('');
const selectedRoleIds = ref<string[]>([]);
const selectedTeamIds = ref<string[]>([]);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    emails: emails.value,
    roleIds: [...selectedRoleIds.value].sort(),
    teamIds: [...selectedTeamIds.value].sort(),
  }),
  backHref: props.management?.indexUrl ?? UsersController.index.url(),
});

const roleOptions = computed<MultiSelectOption[]>(() =>
  props.roles.map((role) => ({
    value: role.id,
    label: role.name,
    badge: role.authority === null ? undefined : t('i18n.pages.users.invite.elevated'),
    disabled: !role.can_assign,
    disabledReason: t('i18n.pages.users.invite.you_may_not_assign_this_role'),
  })),
);

function withSelections(data: Record<string, FormDataConvertible>): Record<string, FormDataConvertible> {
  return {
    ...data,
    role_ids: selectedRoleIds.value,
    team_ids: selectedTeamIds.value,
  };
}
</script>

<template>
  <PageShell
    :title="t('i18n.pages.users.invite.invite_users')"
    :description="t('i18n.pages.users.invite.invitees_appear_in_the_list_immediately_and_complete_their')"
  >
    <Form
      v-bind="management ? { action: management.storeUrl, method: 'post' } : UserInvitationsController.store.form()"
      :transform="withSelections"
      :on-success="markSaved"
      class="flex flex-col gap-6"
      v-slot="{ errors, processing }"
    >
      <UiExtensionPoint name="users.invite.fields" />

      <Card>
        <CardHeader>
          <CardTitle>{{ t('i18n.pages.users.invite.invitation') }}</CardTitle>
          <CardDescription>
            {{ t('i18n.pages.users.invite.separate_multiple_addresses_with_commas_each_address_receives_its') }}
          </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
          <FormField for="invite-emails" :label="t('i18n.pages.users.invite.email_addresses')" :error="errors.emails">
            <Textarea
              id="invite-emails"
              v-model="emails"
              name="emails"
              rows="4"
              :placeholder="t('i18n.pages.users.invite.anna_example_com_ben_example_com')"
              :aria-invalid="Boolean(errors.emails)"
            />
          </FormField>

          <FormActions v-if="management" :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </CardContent>
      </Card>

      <Card v-if="!management">
        <CardHeader>
          <CardTitle>{{ t('i18n.pages.users.invite.roles_and_teams') }}</CardTitle>
          <CardDescription>
            {{ t('i18n.pages.users.invite.applies_to_all_addresses_in_this_invitation_both_can') }}
          </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
          <FormField
            for="invite-roles"
            :label="t('i18n.pages.users.invite.roles')"
            :error="errors.role_ids"
            class="sm:max-w-sm"
          >
            <MultiSelect
              id="invite-roles"
              v-model="selectedRoleIds"
              :options="roleOptions"
              :placeholder="t('i18n.pages.users.invite.select_roles')"
              :empty-label="t('i18n.pages.users.invite.there_are_no_roles')"
              :aria-label="t('i18n.pages.users.invite.roles')"
            />
          </FormField>

          <FormField
            for="invite-teams"
            :label="t('i18n.pages.users.invite.teams')"
            :error="errors.team_ids"
            class="sm:max-w-sm"
          >
            <MultiSelect
              id="invite-teams"
              v-model="selectedTeamIds"
              :options="teams"
              :placeholder="t('i18n.pages.users.invite.select_teams')"
              :empty-label="t('i18n.pages.users.invite.there_are_no_teams')"
              :aria-label="t('i18n.pages.users.invite.teams')"
            />
          </FormField>

          <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </CardContent>
      </Card>
    </Form>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
