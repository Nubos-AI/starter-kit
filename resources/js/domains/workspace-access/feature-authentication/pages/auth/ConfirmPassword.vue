<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
  index as confirmOptions,
  store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import { PasswordInput } from '@/domains/workspace-access/ui-auth';
import { store } from '@/routes/password/confirm';
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Spinner } from '@/shared/ui/primitives/spinner';
import PasskeyVerify from '../../ui/PasskeyVerify.vue';

const { t } = useI18n();

defineOptions({
  layout: {
    title: 'i18n.pages.auth.confirm_password.confirm_password',
    description: 'i18n.pages.auth.confirm_password.this_is_a_protected_area_of_the_application_please',
  },
});
</script>

<template>
  <Head :title="t('i18n.pages.auth.confirm_password.confirm_password')" />

  <PasskeyVerify
    :routes="{
      options: confirmOptions(),
      submit: confirmStore(),
    }"
    :label="t('i18n.pages.auth.confirm_password.confirm_with_a_passkey')"
    :loading-label="t('i18n.pages.auth.confirm_password.confirming')"
    :separator="t('i18n.pages.auth.confirm_password.or_confirm_with_your_password')"
  />

  <Form v-bind="store.form()" reset-on-success v-slot="{ errors, processing }" class="grid gap-6">
    <FormField for="password" :label="t('i18n.pages.auth.confirm_password.password')" :error="errors.password">
      <PasswordInput id="password" name="password" required autocomplete="current-password" autofocus />
    </FormField>

    <Button :disabled="processing" data-test="confirm-password-button">
      <Spinner v-if="processing" />
      {{ t('i18n.pages.auth.confirm_password.confirm_password') }}
    </Button>
  </Form>
</template>
