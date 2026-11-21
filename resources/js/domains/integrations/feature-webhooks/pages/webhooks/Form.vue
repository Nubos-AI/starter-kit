<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, router } from '@inertiajs/vue3';
import { Info, RefreshCw, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import WebhookSubscriptionsController from '@/actions/App/Http/Controllers/Webhooks/WebhookSubscriptionsController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { WEBHOOK_STATUS } from '@/shared/lib/statusMaps';
import type { SelectOption } from '@/shared/types/ui';
import AlertError from '@/shared/ui/AlertError.vue';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import DetailItem from '@/shared/ui/DetailItem.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Alert, AlertDescription, AlertTitle } from '@/shared/ui/primitives/alert';
import { Button } from '@/shared/ui/primitives/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Input } from '@/shared/ui/primitives/input';
import type { MultiSelectOption } from '@/shared/ui/primitives/multi-select';
import { MultiSelect } from '@/shared/ui/primitives/multi-select';
import { StatusBadge } from '@/shared/ui/primitives/status-badge';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import ApiTokenSecretDialog from '../../../../../shared/api-tokens/ui/ApiTokenSecretDialog.vue';

const { t } = useI18n();

interface WebhookSubscriptionPayload {
  id: string;
  name: string;
  targetUrl: string;
  authUsername: string | null;
  status: string;
  eventTypes: string[];
  objectTypeId: string | null;
  teamId: string | null;
  roleName: string | null;
  consecutiveFailures: number;
  lastError: string | null;
  activatedAt: string | null;
  rotatedAt: string | null;
}

const props = defineProps<{
  mode: 'create' | 'edit';
  subscription: WebhookSubscriptionPayload | null;
  secret: string | null;
  eventTypeOptions: SelectOption[];
  objectTypeOptions: SelectOption[];
  roleOptions: SelectOption[];
  teamOptions: SelectOption[];
}>();

const heading = computed<string>(() =>
  props.mode === 'create'
    ? t('i18n.pages.webhooks.form.new_webhook')
    : (props.subscription?.name ?? t('i18n.pages.webhooks.form.webhook')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const ALL_OBJECT_TYPES = 'all';
const WHOLE_SYSTEM = 'system';

const name = ref<string>(props.subscription?.name ?? '');
const targetUrl = ref<string>(props.subscription?.targetUrl ?? '');
const authUsername = ref<string>(props.subscription?.authUsername ?? '');
const authPassword = ref<string>('');
const roleName = ref<string>(props.roleOptions[0]?.value ?? '');
const objectTypeId = ref<string>(props.subscription?.objectTypeId ?? ALL_OBJECT_TYPES);
const eventTypes = ref<string[]>(props.subscription?.eventTypes ?? []);
const teamId = ref<string>(props.subscription?.teamId ?? WHOLE_SYSTEM);

const teamChoices = computed<SelectOption[]>(() => [
  {
    value: WHOLE_SYSTEM,
    label: t('i18n.pages.webhooks.form.whole_system'),
  },
  ...props.teamOptions,
]);

const teamChoice = computed<string | null>({
  get: () => teamId.value,
  set: (value) => {
    teamId.value = value ?? WHOLE_SYSTEM;
  },
});

const objectTypeChoices = computed<SelectOption[]>(() => [
  {
    value: ALL_OBJECT_TYPES,
    label: t('i18n.pages.webhooks.form.all_object_types'),
  },
  ...props.objectTypeOptions,
]);

const roleId = computed<string | null>({
  get: () => (roleName.value === '' ? null : roleName.value),
  set: (value) => {
    roleName.value = value ?? '';
  },
});

const objectTypeChoice = computed<string | null>({
  get: () => objectTypeId.value,
  set: (value) => {
    objectTypeId.value = value ?? ALL_OBJECT_TYPES;
  },
});

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    name: name.value,
    targetUrl: targetUrl.value,
    authUsername: authUsername.value,
    authPassword: authPassword.value,
    roleName: roleName.value,
    objectTypeId: objectTypeId.value,
    teamId: teamId.value,
    eventTypes: [...eventTypes.value].sort(),
  }),
  backHref: WebhookSubscriptionsController.index.url(),
});

const eventTypeChoices = computed<MultiSelectOption[]>(() =>
  props.eventTypeOptions.map((option) => ({
    value: option.value,
    label: option.label,
  })),
);

function withSelections(data: Record<string, FormDataConvertible>): Record<string, FormDataConvertible> {
  return {
    ...data,
    auth_username: authUsername.value.trim(),
    auth_password: authPassword.value,
    roleName: roleName.value,
    event_types: eventTypes.value,
    object_type_id: objectTypeId.value === ALL_OBJECT_TYPES ? null : objectTypeId.value,
    team_id: teamId.value === WHOLE_SYSTEM ? null : teamId.value,
  };
}

const rotateOpen = ref<boolean>(false);
const deleteOpen = ref<boolean>(false);

const isEdit = computed<boolean>(() => props.mode === 'edit');

function originOf(url: string): string | null {
  try {
    const parsed = new URL(url);

    return `${parsed.protocol}//${parsed.host}`.toLowerCase();
  } catch {
    return null;
  }
}

const requiresNewPassword = computed<boolean>(
  () =>
    isEdit.value &&
    props.subscription !== null &&
    props.subscription.authUsername !== null &&
    authUsername.value.trim() !== '' &&
    authPassword.value === '' &&
    originOf(targetUrl.value) !== originOf(props.subscription.targetUrl),
);

const formAction = computed(() =>
  isEdit.value
    ? WebhookSubscriptionsController.update.form({
        subscription: props.subscription!.id,
      })
    : WebhookSubscriptionsController.store.form(),
);

const status = computed<string>(() => props.subscription?.status ?? 'pending');

const isPending = computed<boolean>(() => props.subscription?.status === 'pending');

function recheckChallenge(): void {
  router.post(
    WebhookSubscriptionsController.recheck.url({
      subscription: props.subscription!.id,
    }),
  );
}

function rotateSecret(): void {
  rotateOpen.value = false;

  router.post(
    WebhookSubscriptionsController.rotateSecret.url({
      subscription: props.subscription!.id,
    }),
  );
}

function deleteSubscription(): void {
  deleteOpen.value = false;

  router.delete(
    WebhookSubscriptionsController.destroy.url({
      subscription: props.subscription!.id,
    }),
  );
}
</script>

<template>
  <PageShell
    :title="heading"
    :description="
      mode === 'create'
        ? t('i18n.pages.webhooks.form.the_secret_is_shown_only_once_after_creation_store')
        : t('i18n.pages.webhooks.form.edit_this_subscription_s_destination_events_and_filters')
    "
    :head-title="
      mode === 'create' ? t('i18n.pages.webhooks.form.new_webhook') : t('i18n.pages.webhooks.form.edit_webhook')
    "
  >
    <template #actions>
      <StatusBadge v-if="isEdit" :map="WEBHOOK_STATUS" :status="status" />
    </template>

    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.webhooks.form.details') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.webhooks.form.each_delivery_is_signed_changing_the_destination_url_triggers') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form
          v-bind="formAction"
          :transform="withSelections"
          :on-success="markSaved"
          class="flex flex-col gap-4"
          v-slot="{ errors, processing }"
        >
          <FormField
            for="webhook-name"
            :label="t('i18n.pages.webhooks.form.name')"
            :error="errors.name"
            class="sm:max-w-sm"
          >
            <Input
              id="webhook-name"
              v-model="name"
              name="name"
              type="text"
              required
              :placeholder="t('i18n.pages.webhooks.form.e_g_customer_portal')"
              data-webhook-name
            />
          </FormField>

          <FormField
            for="webhook-target-url"
            :label="t('i18n.pages.webhooks.form.destination_url')"
            :error="errors.target_url"
            class="sm:max-w-xl"
          >
            <Input
              id="webhook-target-url"
              v-model="targetUrl"
              name="target_url"
              type="url"
              required
              :placeholder="t('i18n.pages.webhooks.form.https_hooks_example_com_webhook')"
              data-webhook-target-url
            />
          </FormField>

          <div class="grid gap-4 sm:max-w-xl sm:grid-cols-2">
            <FormField
              for="webhook-auth-username"
              :label="t('i18n.pages.webhooks.form.basic_auth_username')"
              :error="errors.auth_username"
            >
              <Input
                id="webhook-auth-username"
                v-model="authUsername"
                name="auth_username"
                type="text"
                autocomplete="off"
                :placeholder="t('i18n.pages.webhooks.form.optional')"
                data-webhook-auth-username
              />
            </FormField>

            <FormField
              for="webhook-auth-password"
              :label="t('i18n.pages.webhooks.form.basic_auth_password')"
              :error="errors.auth_password"
            >
              <Input
                id="webhook-auth-password"
                v-model="authPassword"
                name="auth_password"
                type="password"
                autocomplete="new-password"
                :placeholder="
                  requiresNewPassword
                    ? t('i18n.pages.webhooks.form.required_for_the_new_server')
                    : isEdit && subscription!.authUsername !== null
                      ? t('i18n.pages.webhooks.form.leave_unchanged')
                      : t('i18n.pages.webhooks.form.optional')
                "
                data-webhook-auth-password
              />
              <p v-if="requiresNewPassword" class="text-xs text-muted-foreground" data-webhook-password-required>
                {{ t('i18n.pages.webhooks.form.the_new_destination_url_points_to_another_server') }}
              </p>
            </FormField>
          </div>

          <p class="text-xs text-muted-foreground sm:max-w-xl">
            {{ t('i18n.pages.webhooks.form.each_delivery_authenticates_with_a_username_and_password_using') }}
          </p>

          <FormField
            v-if="!isEdit"
            for="webhook-role"
            :label="t('i18n.pages.webhooks.form.role')"
            :error="errors.roleName"
            :hint="t('i18n.pages.webhooks.form.the_webhook_s_service_user_receives_this_role_roles')"
            class="sm:max-w-sm"
          >
            <Combobox
              id="webhook-role"
              v-model="roleId"
              :options="props.roleOptions"
              :searchable="props.roleOptions.length > 8"
              :placeholder="t('i18n.pages.webhooks.form.select_role')"
              :aria-label="t('i18n.pages.webhooks.form.role')"
              data-webhook-role
            />
          </FormField>

          <FormField v-else :label="t('i18n.pages.webhooks.form.role')" class="sm:max-w-sm">
            <p class="text-sm text-muted-foreground">
              {{ subscription!.roleName ?? t('i18n.pages.webhooks.form.no_role') }}
            </p>
          </FormField>

          <FormField
            for="webhook-team"
            :label="t('i18n.pages.webhooks.form.team')"
            :error="errors.team_id"
            class="sm:max-w-sm"
          >
            <Combobox
              id="webhook-team"
              v-model="teamChoice"
              :options="teamChoices"
              :searchable="teamChoices.length > 8"
              :placeholder="t('i18n.pages.webhooks.form.whole_system')"
              :aria-label="t('i18n.pages.webhooks.form.team')"
              data-webhook-team
            />
            <p class="text-xs text-muted-foreground" data-webhook-team-hint>
              {{ t('i18n.pages.webhooks.form.without_a_team_the_webhook_applies_to_the_whole_system') }}
            </p>
          </FormField>

          <FormField
            for="webhook-object-type"
            :label="t('i18n.pages.webhooks.form.object_type_filter')"
            :error="errors.object_type_id"
            :hint="t('i18n.pages.webhooks.form.optional_without_a_selection_all_object_types_are_sent')"
            class="sm:max-w-sm"
          >
            <Combobox
              id="webhook-object-type"
              v-model="objectTypeChoice"
              :options="objectTypeChoices"
              :searchable="objectTypeChoices.length > 8"
              :placeholder="t('i18n.pages.webhooks.form.all_object_types')"
              :aria-label="t('i18n.pages.webhooks.form.object_type_filter')"
              data-webhook-object-type
            />
          </FormField>

          <FormField
            for="webhook-event-types"
            :label="t('i18n.pages.webhooks.form.event_types')"
            :error="errors.event_types"
            class="sm:max-w-sm"
          >
            <MultiSelect
              id="webhook-event-types"
              v-model="eventTypes"
              :options="eventTypeChoices"
              :placeholder="t('i18n.pages.webhooks.form.select_events')"
              :empty-label="t('i18n.pages.webhooks.form.no_events_available')"
              :aria-label="t('i18n.pages.webhooks.form.event_types')"
            />
          </FormField>

          <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </Form>
      </CardContent>
    </Card>

    <Card v-if="isEdit">
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.webhooks.form.delivery') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.webhooks.form.challenge_status_and_recent_delivery_attempts') }}
        </CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-4">
        <Alert v-if="isPending" role="status" data-webhook-pending-hint>
          <Info />
          <AlertTitle>{{
            t('i18n.pages.webhooks.form.the_destination_url_has_not_confirmed_the_challenge_yet')
          }}</AlertTitle>
          <AlertDescription>
            <p>
              {{ t('i18n.pages.webhooks.form.the_endpoint_must_accept_a_post_with_the_body') }}
              <code>{{ t('i18n.pages.webhooks.form.challenge') }}</code>
              {{ t('i18n.pages.webhooks.form.respond_with_2xx_and_return_the_same_value_under') }}
              <code>{{ t('i18n.pages.webhooks.form.challenge_2') }}</code>
              {{ t('i18n.pages.webhooks.form.an_arbitrary_address_such_as_a_home_page_does') }}
            </p>
          </AlertDescription>
        </Alert>

        <dl class="grid gap-3 sm:grid-cols-3">
          <DetailItem :label="t('i18n.pages.webhooks.form.consecutive_failures')">
            {{ subscription!.consecutiveFailures }}
          </DetailItem>
          <DetailItem :label="t('i18n.pages.webhooks.form.activated_on')">
            {{ subscription!.activatedAt ?? '—' }}
          </DetailItem>
          <DetailItem :label="t('i18n.pages.webhooks.form.secret_rotated_on')">
            {{ subscription!.rotatedAt ?? '—' }}
          </DetailItem>
        </dl>

        <AlertError
          v-if="subscription!.lastError !== null"
          data-webhook-last-error
          :title="t('i18n.pages.webhooks.form.last_delivery_error')"
          :message="subscription!.lastError"
        />

        <div class="flex flex-wrap items-center gap-2">
          <Button type="button" variant="outline" data-webhook-recheck @click="recheckChallenge">
            <RefreshCw class="size-4" />
            {{ t('i18n.pages.webhooks.form.check_again') }}
          </Button>
          <Button type="button" variant="outline" data-webhook-rotate @click="rotateOpen = true">
            <RotateCcw class="size-4" />
            {{ t('i18n.pages.webhooks.form.rotate_secret') }}
          </Button>
          <Button type="button" variant="destructive" data-webhook-delete @click="deleteOpen = true">
            {{ t('i18n.pages.webhooks.form.delete_webhook') }}
          </Button>
        </div>
      </CardContent>
    </Card>

    <ApiTokenSecretDialog
      :secret="props.secret"
      :title="t('i18n.pages.webhooks.form.copy_secret_now')"
      :description="t('i18n.pages.webhooks.form.this_secret_will_never_be_shown_again_once_you')"
      :copy-label="t('i18n.pages.webhooks.form.copy_secret')"
      :copy-error-message="t('i18n.pages.webhooks.form.the_secret_could_not_be_copied')"
      :secret-attrs="{ 'data-webhook-secret': '' }"
      :copy-attrs="{ 'data-webhook-copy': '' }"
    />

    <ConfirmDialog
      v-model:open="rotateOpen"
      :title="t('i18n.pages.webhooks.form.rotate_secret_2')"
      :description="t('i18n.pages.webhooks.form.a_new_secret_is_generated_and_shown_once_it')"
      :confirm-label="t('i18n.pages.webhooks.form.rotate')"
      @confirm="rotateSecret"
    />

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t('i18n.pages.webhooks.form.delete_webhook_2')"
      :description="t('i18n.pages.webhooks.form.the_webhook_will_no_longer_receive_events_this_action')"
      :confirm-label="t('i18n.pages.webhooks.form.delete')"
      variant="destructive"
      @confirm="deleteSubscription"
    />

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
