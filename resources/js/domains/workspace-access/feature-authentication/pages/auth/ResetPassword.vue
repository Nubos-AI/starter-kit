<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PasswordConfirmationFields } from '@/domains/workspace-access/ui-auth';
import { update } from '@/routes/password';
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';
import { Spinner } from '@/shared/ui/primitives/spinner';

const { t } = useI18n();

defineOptions({
  layout: {
    title: 'i18n.pages.auth.reset_password.reset_password',
    description: 'i18n.pages.auth.reset_password.please_enter_your_new_password',
  },
});

const props = defineProps<{
  token: string;
  email: string;
  passwordRules: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
  <Head :title="t('i18n.pages.auth.reset_password.reset_password')" />

  <Form
    v-bind="update.form()"
    :transform="(data) => ({ ...data, token, email })"
    :reset-on-success="['password', 'password_confirmation']"
    v-slot="{ errors, processing }"
  >
    <div class="grid gap-6">
      <FormField for="email" :label="t('i18n.pages.auth.reset_password.email_address')" :error="errors.email">
        <Input id="email" type="email" name="email" autocomplete="email" v-model="inputEmail" readonly />
      </FormField>

      <PasswordConfirmationFields
        :password-label="t('i18n.pages.auth.reset_password.password')"
        :confirmation-label="t('i18n.pages.auth.reset_password.confirm_password')"
        :password-rules="passwordRules"
        :password-error="errors.password"
        :confirmation-error="errors.password_confirmation"
        autofocus
      />

      <Button type="submit" class="mt-4" :disabled="processing" data-test="reset-password-button">
        <Spinner v-if="processing" />
        {{ t('i18n.pages.auth.reset_password.reset_password') }}
      </Button>
    </div>
  </Form>
</template>
