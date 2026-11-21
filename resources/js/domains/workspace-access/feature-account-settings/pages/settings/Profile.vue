<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ProfilesController from '@/actions/App/Http/Controllers/Settings/ProfilesController';
import { SalutationSelect } from '@/domains/workspace-access/ui-users';
import { edit as editProfile } from '@/routes/profile';
import { send } from '@/routes/verification';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import Heading from '@/shared/ui/Heading.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Input } from '@/shared/ui/primitives/input';
import TextLink from '@/shared/ui/TextLink.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import DeleteUser from '../../ui/DeleteUser.vue';

const { t } = useI18n();

defineProps<{
  salutations: Array<{ value: string; label: string }>;
  pendingEmail?: string | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const salutation = ref<string>(user.value.salutation ?? '');
const firstName = ref<string>(user.value.first_name ?? '');
const lastName = ref<string>(user.value.last_name ?? '');
const email = ref<string>(user.value.email);
const currentPassword = ref<string>('');

const isEmailChanged = computed(() => email.value.trim().toLowerCase() !== user.value.email);

watch(
  () => page.props.status,
  (status) => {
    if (status !== 'verification-link-sent') {
      return;
    }

    toast.success(t('i18n.pages.settings.profile.a_new_verification_link_has_been_sent_to_your'));
  },
  { immediate: true },
);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    salutation: salutation.value,
    firstName: firstName.value,
    lastName: lastName.value,
    email: email.value,
  }),
  backHref: editProfile.url(),
});

function handleSaved(): void {
  currentPassword.value = '';
  email.value = user.value.email;
  markSaved();
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <Head :title="t('i18n.pages.settings.profile.profile_settings')" />

    <Heading
      variant="small"
      :title="t('i18n.pages.settings.profile.profile')"
      :description="t('i18n.pages.settings.profile.change_your_name_and_email_address')"
    />

    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.settings.profile.personal_details') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.settings.profile.a_changed_email_address_must_be_verified_again') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form
          v-bind="ProfilesController.update.form()"
          :on-success="handleSaved"
          class="flex flex-col gap-4"
          v-slot="{ errors, processing }"
        >
          <div class="grid gap-4 sm:grid-cols-2">
            <FormField for="salutation" :label="t('i18n.pages.settings.profile.salutation')" :error="errors.salutation">
              <SalutationSelect id="salutation" v-model="salutation" :options="salutations" />
            </FormField>

            <FormField for="email" :label="t('i18n.pages.settings.profile.email_address')" :error="errors.email">
              <Input
                id="email"
                v-model="email"
                type="email"
                name="email"
                required
                autocomplete="username"
                :placeholder="t('i18n.pages.settings.profile.email_address')"
              />
              <p v-if="pendingEmail" class="text-xs text-muted-foreground" data-test="pending-email-hint">
                {{ t('i18n.pages.settings.profile.pending_email_change', { value1: pendingEmail }) }}
              </p>
            </FormField>

            <FormField for="first_name" :label="t('i18n.pages.settings.profile.first_name')" :error="errors.first_name">
              <Input
                id="first_name"
                v-model="firstName"
                name="first_name"
                required
                autocomplete="given-name"
                :placeholder="t('i18n.pages.settings.profile.first_name')"
              />
            </FormField>

            <FormField for="last_name" :label="t('i18n.pages.settings.profile.last_name')" :error="errors.last_name">
              <Input
                id="last_name"
                v-model="lastName"
                name="last_name"
                required
                autocomplete="family-name"
                :placeholder="t('i18n.pages.settings.profile.last_name')"
              />
            </FormField>
          </div>

          <FormField
            v-if="isEmailChanged"
            data-test="current-password-field"
            for="current_password"
            :label="t('i18n.pages.settings.profile.current_password')"
            :error="errors.current_password"
            :hint="t('i18n.pages.settings.profile.confirm_the_email_change_with_your_current_password')"
            class="sm:max-w-sm"
          >
            <Input
              id="current_password"
              v-model="currentPassword"
              type="password"
              name="current_password"
              required
              autocomplete="current-password"
            />
          </FormField>

          <div
            v-if="page.props.mustVerifyEmail && !user.email_verified_at"
            class="text-sm"
            data-test="verification-hint"
          >
            <p class="text-muted-foreground">
              {{ t('i18n.pages.settings.profile.your_email_address_has_not_been_verified_yet') }}
              <TextLink :href="send()" as="button">
                {{ t('i18n.pages.settings.profile.resend_verification_email') }}
              </TextLink>
            </p>
          </div>

          <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </Form>
      </CardContent>
    </Card>

    <DeleteUser />

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </div>
</template>
