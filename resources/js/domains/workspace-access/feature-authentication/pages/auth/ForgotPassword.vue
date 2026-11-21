<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { login } from '@/routes';
import { email } from '@/routes/password';
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';
import { Spinner } from '@/shared/ui/primitives/spinner';
import TextLink from '@/shared/ui/TextLink.vue';

const { t } = useI18n();

defineOptions({
  layout: {
    title: 'i18n.pages.auth.forgot_password.forgot_password',
    description: 'i18n.pages.auth.forgot_password.enter_your_email_address_to_receive_a_password_reset',
  },
});

defineProps<{
  status?: string;
}>();
</script>

<template>
  <Head :title="t('i18n.pages.auth.forgot_password.forgot_password')" />

  <div v-if="status" class="text-center text-sm font-medium text-success">
    {{ status }}
  </div>

  <Form v-bind="email.form()" v-slot="{ errors, processing }" class="grid gap-6">
    <FormField for="email" :label="t('i18n.pages.auth.forgot_password.email_address')" :error="errors.email">
      <Input
        id="email"
        type="email"
        name="email"
        autocomplete="off"
        autofocus
        :placeholder="t('i18n.pages.auth.forgot_password.email_example_com')"
      />
    </FormField>

    <Button :disabled="processing" data-test="email-password-reset-link-button">
      <Spinner v-if="processing" />
      {{ t('i18n.pages.auth.forgot_password.send_reset_link') }}
    </Button>
  </Form>

  <div class="space-x-1 text-center text-sm text-muted-foreground">
    <span>{{ t('i18n.pages.auth.forgot_password.or_return_to') }}</span>
    <TextLink :href="login()">{{ t('i18n.pages.auth.forgot_password.sign_in') }}</TextLink>
  </div>
</template>
