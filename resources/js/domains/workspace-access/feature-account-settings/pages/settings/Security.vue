<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import type { Props as ManagePasskeysProps, ManageTwoFactorProps } from '@/domains/workspace-access/ui-auth';
import {
  ManagePasskeys,
  ManageTwoFactor,
  PasswordConfirmationFields,
  PasswordInput,
} from '@/domains/workspace-access/ui-auth';
import { edit as editSecurity } from '@/routes/security';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import CardSection from '@/shared/ui/CardSection.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import Heading from '@/shared/ui/Heading.vue';
import { Card, CardContent } from '@/shared/ui/primitives/card';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

const { t } = useI18n();

type Props = {
  passwordRules: string;
} & ManagePasskeysProps &
  ManageTwoFactorProps;

const props = defineProps<Props>();

const currentPassword = ref<string>('');
const password = ref<string>('');
const passwordConfirmation = ref<string>('');

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    currentPassword: currentPassword.value,
    password: password.value,
    passwordConfirmation: passwordConfirmation.value,
  }),
  backHref: editSecurity.url(),
});

function clearPasswords(): void {
  currentPassword.value = '';
  password.value = '';
  passwordConfirmation.value = '';
}

function onSaved(): void {
  clearPasswords();
  markSaved();
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <Head :title="t('i18n.pages.settings.security.security_settings')" />

    <Heading
      variant="small"
      :title="t('i18n.pages.settings.security.change_password')"
      :description="t('i18n.pages.settings.security.a_long_random_password_protects_your_account_best')"
    />

    <Card data-security-settings>
      <CardContent class="flex flex-col gap-6">
        <CardSection
          :title="t('i18n.pages.settings.security.password')"
          :description="t('i18n.pages.settings.security.enter_your_current_password_to_set_a_new_one')"
          data-security-section="password"
        >
          <Form
            v-bind="SecurityController.update.form()"
            :options="{
              preserveScroll: true,
            }"
            :on-success="onSaved"
            :on-error="clearPasswords"
            class="flex flex-col gap-4"
            v-slot="{ errors, processing }"
          >
            <FormField
              for="current_password"
              :label="t('i18n.pages.settings.security.current_password')"
              :error="errors.current_password"
              class="sm:max-w-sm"
            >
              <PasswordInput
                id="current_password"
                v-model="currentPassword"
                name="current_password"
                autocomplete="current-password"
                :placeholder="t('i18n.pages.settings.security.current_password')"
              />
            </FormField>

            <div class="grid gap-4 sm:grid-cols-2">
              <PasswordConfirmationFields
                v-model:password="password"
                v-model:confirmation="passwordConfirmation"
                :password-label="t('i18n.pages.settings.security.new_password')"
                :confirmation-label="t('i18n.pages.settings.security.confirm_password')"
                :password-rules="props.passwordRules"
                :password-error="errors.password"
                :confirmation-error="errors.password_confirmation"
              />
            </div>

            <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
          </Form>
        </CardSection>

        <ManageTwoFactor
          :canManageTwoFactor="canManageTwoFactor"
          :requiresConfirmation="requiresConfirmation"
          :twoFactorEnabled="twoFactorEnabled"
        />

        <ManagePasskeys :canManagePasskeys="canManagePasskeys" :passkeys="passkeys" />
      </CardContent>
    </Card>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </div>
</template>
