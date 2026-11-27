<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UsersController from '@/actions/App/Http/Controllers/Users/UsersController';
import type {
  AssignableRole,
  SalutationOption,
  TeamRoleAssignment,
  TeamRoleDraft,
  UserFormPayload,
} from '@/domains/workspace-access/data';
import { EffectivePermissions } from '@/domains/workspace-access/ui-authorization';
import { SalutationSelect } from '@/domains/workspace-access/ui-users';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { SelectOption } from '@/shared/types/ui';
import CardSection from '@/shared/ui/CardSection.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Input } from '@/shared/ui/primitives/input';
import type { MultiSelectOption } from '@/shared/ui/primitives/multi-select';
import { MultiSelect } from '@/shared/ui/primitives/multi-select';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import UserPasswordCard from '../../ui/UserPasswordCard.vue';
import UserTeamRolesField from '../../ui/UserTeamRolesField.vue';

const { t } = useI18n();

const props = defineProps<{
  user: UserFormPayload;
  salutations: SalutationOption[];
  roles: AssignableRole[];
  teams: MultiSelectOption[];
  permissions: MultiSelectOption[];
}>();

const salutation = ref<string>(props.user.salutation ?? '');
const email = ref<string>(props.user.email);
const firstName = ref<string>(props.user.first_name ?? '');
const lastName = ref<string>(props.user.last_name ?? '');
const selectedRoleIds = ref<string[]>([...props.user.role_ids]);
const teamRoleRows = ref<TeamRoleDraft[]>(props.user.team_roles.map((teamRole) => ({ ...teamRole })));
const completeTeamRoles = computed<TeamRoleAssignment[]>(() =>
  teamRoleRows.value.filter((row): row is TeamRoleAssignment => row.role_id !== null && row.team_id !== null),
);

const selectedTeamIds = ref<string[]>([...props.user.team_ids]);
const selectedDeniedPermissionIds = ref<string[]>([...props.user.denied_permission_ids]);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    salutation: salutation.value,
    email: email.value,
    firstName: firstName.value,
    lastName: lastName.value,
    roleIds: [...selectedRoleIds.value].sort(),
    teamRoles: completeTeamRoles.value.map((teamRole) => `${teamRole.role_id}|${teamRole.team_id}`).sort(),
    teamIds: [...selectedTeamIds.value].sort(),
    deniedPermissionIds: [...selectedDeniedPermissionIds.value].sort(),
  }),
  backHref: UsersController.index.url(),
});

const roleOptions = computed<MultiSelectOption[]>(() =>
  props.roles
    .filter((role) => role.scope !== 'team' || props.user.role_ids.includes(role.id))
    .map((role) => ({
      value: role.id,
      label: role.name,
      badge: role.authority === null ? undefined : t('i18n.pages.users.form.elevated'),
      disabled: !role.can_assign,
      disabledReason: t('i18n.pages.users.form.you_do_not_have_permission_to_assign_this_role'),
    })),
);

const teamRoleOptions = computed<SelectOption[]>(() =>
  props.roles
    .filter((role) => role.scope === 'team' && role.authority === null)
    .map((role) => ({
      value: role.id,
      label: role.name,
      disabled: !role.can_assign,
      disabledReason: t('i18n.pages.users.form.you_do_not_have_permission_to_assign_this_role'),
    })),
);

const displayName = computed<string>(() => {
  const fullName = [props.user.first_name, props.user.last_name]
    .filter((part): part is string => Boolean(part))
    .join(' ');

  return fullName === '' ? props.user.email : fullName;
});

usePageBreadcrumbs(() => [{ title: displayName.value }]);

const failures = ref<Partial<Record<string, string>>>({});
const saving = ref<boolean>(false);

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    {
      key: 'general',
      title: t('i18n.pages.users.form.basic_information'),
      fields: ['salutation', 'email', 'first_name', 'last_name'],
    },
    {
      key: 'roles',
      title: t('i18n.pages.users.form.roles'),
      fields: ['role_ids'],
    },
    {
      key: 'team_roles',
      title: t('i18n.pages.users.form.team_roles'),
      fields: ['team_roles'],
    },
    {
      key: 'denied_permissions',
      title: t('i18n.pages.users.form.explicitly_denied_permissions'),
      fields: ['denied_permission_ids'],
    },
    {
      key: 'teams',
      title: t('i18n.pages.users.form.teams'),
      fields: ['team_ids'],
    },
    ...(props.user.can_update_password
      ? [
          {
            key: 'password',
            title: t('i18n.pages.users.form.sections.password'),
          },
        ]
      : []),
  ],
  () => failures.value,
);

function onSaved(): void {
  failures.value = {};
  markSaved();
}

function onFailed(errors: Partial<Record<string, string>>): void {
  failures.value = errors;
  revealErrors(errors);
}

const deniedPermissionNames = computed<string[]>(() =>
  props.permissions
    .filter((permission) => selectedDeniedPermissionIds.value.includes(permission.value))
    .map((permission) => permission.label),
);

function withRoles(data: Record<string, FormDataConvertible>): Record<string, FormDataConvertible> {
  return {
    ...data,
    role_ids: selectedRoleIds.value,
    team_roles: completeTeamRoles.value.map((teamRole) => ({
      role_id: teamRole.role_id,
      team_id: teamRole.team_id,
    })),
    team_ids: selectedTeamIds.value,
    denied_permission_ids: selectedDeniedPermissionIds.value,
  };
}
</script>

<template>
  <PageShell
    :title="displayName"
    :description="t('i18n.pages.users.form.this_user_s_basic_information_and_roles')"
    :head-title="t('i18n.pages.users.form.user', { value1: displayName })"
  >
    <SectionNav
      v-model="activeSection"
      :entries="sections"
      :label="t('i18n.pages.users.form.sections.label')"
      data-user-sections
    >
      <Form
        v-bind="UsersController.update.form({ user: user.id })"
        :transform="withRoles"
        :on-success="onSaved"
        :on-error="onFailed"
        :on-start="() => (saving = true)"
        :on-finish="() => (saving = false)"
        class="flex flex-col gap-6"
        v-slot="{ errors }"
      >
        <SectionPanel section="general">
          <CardSection
            :title="t('i18n.pages.users.form.basic_information')"
            :description="t('i18n.pages.users.form.a_changed_email_address_must_be_verified_again')"
          >
            <div class="grid gap-4 sm:grid-cols-2">
              <FormField
                for="user-salutation"
                :label="t('i18n.pages.users.form.salutation')"
                :error="errors.salutation"
              >
                <SalutationSelect
                  id="user-salutation"
                  v-model="salutation"
                  :options="salutations"
                  :invalid="Boolean(errors.salutation)"
                />
              </FormField>

              <FormField for="user-email" :label="t('i18n.pages.users.form.email')" :error="errors.email">
                <Input
                  id="user-email"
                  v-model="email"
                  name="email"
                  type="email"
                  :readonly="!user.can_update_password"
                  :aria-invalid="Boolean(errors.email)"
                  required
                />
                <p v-if="user.pending_email" class="text-xs text-muted-foreground" data-test="pending-email-hint">
                  {{ t('i18n.pages.users.form.pending_email_change', { value1: user.pending_email }) }}
                </p>
              </FormField>

              <FormField
                for="user-first-name"
                :label="t('i18n.pages.users.form.first_name')"
                :error="errors.first_name"
              >
                <Input
                  id="user-first-name"
                  v-model="firstName"
                  name="first_name"
                  :aria-invalid="Boolean(errors.first_name)"
                  required
                />
              </FormField>

              <FormField for="user-last-name" :label="t('i18n.pages.users.form.last_name')" :error="errors.last_name">
                <Input
                  id="user-last-name"
                  v-model="lastName"
                  name="last_name"
                  :aria-invalid="Boolean(errors.last_name)"
                  required
                />
              </FormField>
            </div>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="roles">
          <CardSection
            :title="t('i18n.pages.users.form.roles')"
            :description="t('i18n.pages.users.form.multiple_roles_are_allowed_their_permissions_add_up_but')"
          >
            <FormField
              for="user-roles"
              :label="t('i18n.pages.users.form.assigned_roles')"
              :error="errors.role_ids"
              class="sm:max-w-sm"
            >
              <MultiSelect
                id="user-roles"
                v-model="selectedRoleIds"
                :disabled="!user.can_manage_access"
                :options="roleOptions"
                :placeholder="t('i18n.pages.users.form.select_roles')"
                :empty-label="t('i18n.pages.users.form.there_are_no_roles')"
                :aria-label="t('i18n.pages.users.form.assigned_roles')"
              />
            </FormField>

            <div class="flex flex-col gap-2">
              <p class="text-sm font-medium">
                {{ t('i18n.pages.users.form.effective_permissions') }}
              </p>
              <EffectivePermissions
                :roles="roles"
                :selected-ids="selectedRoleIds"
                :denied-names="deniedPermissionNames"
              />
            </div>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="team_roles" data-team-roles-card>
          <CardSection
            :title="t('i18n.pages.users.form.team_roles')"
            :description="t('i18n.pages.users.form.a_team_role_applies_only_while_the_user_works_in_that_team')"
          >
            <UserTeamRolesField
              v-model="teamRoleRows"
              :role-options="teamRoleOptions"
              :team-options="teams"
              :disabled="!user.can_manage_access"
              :error="errors.team_roles"
            />
          </CardSection>
        </SectionPanel>

        <SectionPanel section="denied_permissions">
          <CardSection
            :title="t('i18n.pages.users.form.explicitly_denied_permissions')"
            :description="t('i18n.pages.users.form.a_denial_overrides_every_role_of_this_user_and')"
          >
            <FormField
              for="user-denied"
              :label="t('i18n.pages.users.form.denied_permissions')"
              :error="errors.denied_permission_ids"
              class="sm:max-w-sm"
            >
              <MultiSelect
                id="user-denied"
                v-model="selectedDeniedPermissionIds"
                :disabled="!user.can_manage_access"
                :options="permissions"
                :placeholder="t('i18n.pages.users.form.select_permissions')"
                :empty-label="t('i18n.pages.users.form.there_are_no_permissions')"
                :aria-label="t('i18n.pages.users.form.denied_permissions')"
              />
            </FormField>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="teams">
          <CardSection
            :title="t('i18n.pages.users.form.teams')"
            :description="t('i18n.pages.users.form.membership_determines_which_teams_this_user_can_select_in')"
          >
            <FormField
              for="user-teams"
              :label="t('i18n.pages.users.form.assigned_teams')"
              :error="errors.team_ids"
              class="sm:max-w-sm"
            >
              <MultiSelect
                id="user-teams"
                v-model="selectedTeamIds"
                :disabled="!user.can_manage_access"
                :options="teams"
                :placeholder="t('i18n.pages.users.form.select_teams')"
                :empty-label="t('i18n.pages.users.form.there_are_no_teams')"
                :aria-label="t('i18n.pages.users.form.assigned_teams')"
              />
            </FormField>
          </CardSection>
        </SectionPanel>
      </Form>

      <SectionPanel v-if="user.can_update_password" section="password" bare>
        <UserPasswordCard
          :user-id="user.id"
          :can-receive-reset-link="user.status === 'accepted'"
          :back-href="UsersController.index.url()"
        />
      </SectionPanel>

      <template #actions>
        <FormActions :dirty="isDirty" :processing="saving" @cancel="requestLeave" />
      </template>
    </SectionNav>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
