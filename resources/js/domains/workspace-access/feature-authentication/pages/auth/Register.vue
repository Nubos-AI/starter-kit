<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { SalutationOption } from '@/domains/workspace-access/data';
import { PasswordConfirmationFields } from '@/domains/workspace-access/ui-auth';
import { SalutationSelect } from '@/domains/workspace-access/ui-users';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';
import { Spinner } from '@/shared/ui/primitives/spinner';
import TextLink from '@/shared/ui/TextLink.vue';

const { t } = useI18n();

const page = usePage();

const requiresCompanyName = computed(() => page.props.registration?.requiresCompanyName !== false);

defineProps<{
  passwordRules: string;
  salutations: SalutationOption[];
}>();

const salutation = ref<string>('unknown');

defineOptions({
  layout: {
    title: 'i18n.pages.auth.register.create_account',
    description: 'i18n.pages.auth.register.enter_your_details_to_create_an_account',
  },
});
</script>

<template>
  <Head :title="t('i18n.pages.auth.register.register')" />

  <Form
    v-bind="store.form()"
    :reset-on-success="['password', 'password_confirmation']"
    v-slot="{ errors, processing }"
    class="flex flex-col gap-6"
  >
    <div class="grid gap-6">
      <FormField
        v-if="requiresCompanyName"
        for="company_name"
        :label="t('i18n.pages.auth.register.company_name')"
        :error="errors.company_name"
      >
        <Input
          id="company_name"
          type="text"
          required
          autofocus
          :tabindex="1"
          autocomplete="organization"
          name="company_name"
          :placeholder="t('i18n.pages.auth.register.company_name')"
        />
      </FormField>

      <FormField for="salutation" :label="t('i18n.pages.auth.register.salutation')" :error="errors.salutation">
        <SalutationSelect id="salutation" v-model="salutation" :options="salutations" :tabindex="2" />
      </FormField>

      <FormField for="first_name" :label="t('i18n.pages.auth.register.first_name')" :error="errors.first_name">
        <Input
          id="first_name"
          type="text"
          required
          :autofocus="!requiresCompanyName"
          :tabindex="3"
          autocomplete="given-name"
          name="first_name"
          :placeholder="t('i18n.pages.auth.register.first_name')"
        />
      </FormField>

      <FormField for="last_name" :label="t('i18n.pages.auth.register.last_name')" :error="errors.last_name">
        <Input
          id="last_name"
          type="text"
          required
          :tabindex="4"
          autocomplete="family-name"
          name="last_name"
          :placeholder="t('i18n.pages.auth.register.last_name')"
        />
      </FormField>

      <FormField for="email" :label="t('i18n.pages.auth.register.email_address')" :error="errors.email">
        <Input
          id="email"
          type="email"
          required
          :tabindex="5"
          autocomplete="email"
          name="email"
          :placeholder="t('i18n.pages.auth.register.email_example_com')"
        />
      </FormField>

      <PasswordConfirmationFields
        :password-label="t('i18n.pages.auth.register.password')"
        :confirmation-label="t('i18n.pages.auth.register.confirm_password')"
        :password-rules="passwordRules"
        :password-error="errors.password"
        :confirmation-error="errors.password_confirmation"
        required
        :first-tabindex="6"
      />

      <Button type="submit" class="mt-2" tabindex="8" :disabled="processing" data-test="register-user-button">
        <Spinner v-if="processing" />
        {{ t('i18n.pages.auth.register.create_account') }}
      </Button>
    </div>

    <div class="text-center text-sm text-muted-foreground">
      {{ t('i18n.pages.auth.register.already_have_an_account') }}
      <TextLink :href="login()" class="underline underline-offset-4" :tabindex="9">{{
        t('i18n.pages.auth.register.sign_in')
      }}</TextLink>
    </div>
  </Form>
</template>
